<?php

namespace Grocy\Services;

use Anthropic\Client as AnthropicClient;
use Anthropic\Core\Exceptions\APIException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\RateLimitException;
use Grocy\Controllers\Users\User;
use Grocy\Helpers\Profiles;
use GuzzleHttp\Client;

/**
 * Barcode lookup (Grocy Modern).
 *
 * Grocy itself asks exactly one plugin - Open Food Facts out of the box, which
 * knows food and nothing else, and of what it finds only the name is used.
 * This asks a chain of sources until one knows the barcode:
 *
 *   1. the other profiles - a product the other household already has
 *   2. the Open Facts databases - food, cosmetics/drugstore, other products,
 *      pet food (free, no account)
 *   3. on request: Claude searching the web (needs an API key, costs a few
 *      cents per lookup, capped per month)
 *
 * Nothing is created here without the user confirming a preview first - the
 * result is a proposal: name, package size, product group, location and
 * quantity unit, mapped to what this profile already has.
 */
class BarcodeLookupService extends BaseService
{
	const SETTINGS_FILE = 'barcodelookup.json';
	const CATEGORY_MAP_FILE = 'barcode_category_map.json';
	const DEFAULT_MONTHLY_LIMIT = 100;

	// Claude
	const AI_MODEL = 'claude-opus-5-5';
	const AI_MAX_SEARCHES = 4;
	// Published prices at the time of writing, for the "estimated cost" shown
	// in the settings - the bill is what counts
	const AI_PRICE_INPUT_PER_MTOK = 4.0;
	const AI_PRICE_OUTPUT_PER_MTOK = 20.0;
	const AI_PRICE_PER_SEARCH = 0.01;

	const OPEN_FACTS = [
		'https://world.openfoodfacts.org' => 'Open Food Facts',
		'https://world.openbeautyfacts.org' => 'Open Beauty Facts',
		'https://world.openproductsfacts.org' => 'Open Products Facts',
		'https://world.openpetfoodfacts.org' => 'Open Pet Food Facts'
	];

	private function T(string $text, ...$placeholders): string
	{
		return LocalizationService::GetInstance()->__t($text, ...$placeholders);
	}

	private static function Language(): string
	{
		return substr(GROCY_LOCALE, 0, 2);
	}

	public static function NormalizeBarcode(string $barcode): string
	{
		return preg_replace('/[^0-9A-Za-z]/', '', trim($barcode));
	}

	// --- settings (shared by all profiles - one key, one bill) --------------

	private static function SettingsPath(): string
	{
		return GROCY_DATAPATH . '/' . self::SETTINGS_FILE;
	}

	public static function Settings(): array
	{
		$settings = [];

		if (file_exists(self::SettingsPath()))
		{
			$settings = json_decode((string)file_get_contents(self::SettingsPath()), true);
		}

		$settings = is_array($settings) ? $settings : [];
		return $settings + ['ai_enabled' => false, 'api_key' => '', 'monthly_limit' => self::DEFAULT_MONTHLY_LIMIT, 'usage' => []];
	}

	private static function SaveSettings(array $settings): void
	{
		$temp = self::SettingsPath() . '.tmp';
		file_put_contents($temp, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
		@chmod($temp, 0600);
		rename($temp, self::SettingsPath());
	}

	// A key in the settings wins; otherwise the environment (docker-compose)
	private static function ApiKey(array $settings): string
	{
		$key = trim((string)($settings['api_key'] ?? ''));
		return $key !== '' ? $key : (string)getenv('ANTHROPIC_API_KEY');
	}

	public function CanManageSettings(): bool
	{
		return GROCY_PROFILE_ID === Profiles::MAIN && User::HasPermissions(User::PERMISSION_ADMIN);
	}

	public function GetSettingsForUi(): array
	{
		$settings = self::Settings();
		$storedKey = trim((string)$settings['api_key']);
		$month = date('Y-m');

		return [
			'ai_enabled' => (bool)$settings['ai_enabled'],
			'monthly_limit' => (int)$settings['monthly_limit'],
			// The key itself never leaves the server again
			'has_key' => self::ApiKey($settings) !== '',
			'key_hint' => $storedKey !== '' ? '…' . substr($storedKey, -4) : null,
			'key_from_environment' => $storedKey === '' && getenv('ANTHROPIC_API_KEY') !== false && getenv('ANTHROPIC_API_KEY') !== '',
			'usage' => $settings['usage'][$month] ?? ['lookups' => 0, 'searches' => 0, 'input_tokens' => 0, 'output_tokens' => 0],
			'usage_month' => $month,
			'model' => self::AI_MODEL,
			'can_manage' => $this->CanManageSettings()
		];
	}

	public function UpdateSettings(array $data): void
	{
		if (!$this->CanManageSettings())
		{
			throw new BarcodeLookupPermissionException($this->T('Only an administrator of the main profile can change this'));
		}

		$settings = self::Settings();
		$settings['ai_enabled'] = filter_var($data['ai_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
		$settings['monthly_limit'] = max(0, min(10000, (int)($data['monthly_limit'] ?? self::DEFAULT_MONTHLY_LIMIT)));

		// Empty means "keep the stored key"; removing it is explicit
		if (!empty($data['remove_key']))
		{
			$settings['api_key'] = '';
		}
		elseif (isset($data['api_key']) && trim((string)$data['api_key']) !== '')
		{
			$settings['api_key'] = trim((string)$data['api_key']);
		}

		self::SaveSettings($settings);
	}

	// Checks the key without spending anything: listing a model is free
	public function TestApiKey(): array
	{
		if (!$this->CanManageSettings())
		{
			throw new BarcodeLookupPermissionException($this->T('Only an administrator of the main profile can change this'));
		}

		$key = self::ApiKey(self::Settings());

		if ($key === '')
		{
			return ['ok' => false, 'message' => $this->T('No API key is stored')];
		}

		try
		{
			$client = new AnthropicClient(apiKey: $key, requestOptions: ['timeout' => 15, 'maxRetries' => 1]);
			$model = $client->models->retrieve(self::AI_MODEL);
			return ['ok' => true, 'message' => $this->T('The key works - %s is available', $model->displayName ?? self::AI_MODEL)];
		}
		catch (AuthenticationException $ex)
		{
			return ['ok' => false, 'message' => $this->T('The key was not accepted')];
		}
		catch (APIException $ex)
		{
			return ['ok' => false, 'message' => $ex->getMessage()];
		}
	}

	private function AiStatus(): array
	{
		$settings = self::Settings();
		$used = (int)($settings['usage'][date('Y-m')]['lookups'] ?? 0);
		$limit = (int)$settings['monthly_limit'];

		if (!$settings['ai_enabled'] || self::ApiKey($settings) === '')
		{
			return ['available' => false, 'reason' => 'disabled', 'remaining' => 0];
		}

		if ($used >= $limit)
		{
			return ['available' => false, 'reason' => 'limit', 'remaining' => 0];
		}

		return ['available' => true, 'reason' => null, 'remaining' => $limit - $used];
	}

	// --- what this profile has, for the preview --------------------------

	private function Options(): array
	{
		$query = fn (string $sql) => DatabaseService::GetInstance()->ExecuteDbQuery($sql)->fetchAll(\PDO::FETCH_ASSOC);

		return [
			'product_groups' => $query('SELECT id, name FROM product_groups WHERE active = 1 ORDER BY name COLLATE NOCASE'),
			'locations' => $query('SELECT id, name FROM locations WHERE active = 1 ORDER BY name COLLATE NOCASE'),
			'quantity_units' => $query('SELECT id, name, name_plural FROM quantity_units WHERE active = 1 ORDER BY name COLLATE NOCASE')
		];
	}

	private static function IdByName(array $rows, ?string $name, array $fields = ['name']): ?int
	{
		if ($name === null || trim($name) === '')
		{
			return null;
		}

		$needle = mb_strtolower(trim($name));

		foreach ($rows as $row)
		{
			foreach ($fields as $field)
			{
				if (isset($row[$field]) && mb_strtolower(trim((string)$row[$field])) === $needle)
				{
					return (int)$row['id'];
				}
			}
		}

		return null;
	}

	// The location and unit most products of that group already have - a
	// new pack of toilet paper goes where the other hygiene products are
	private function TypicalForGroup(?int $groupId): array
	{
		if ($groupId === null)
		{
			return ['location_id' => null, 'qu_id' => null];
		}

		$most = function (string $column) use ($groupId)
		{
			$value = DatabaseService::GetInstance()->ExecuteDbQuery('SELECT ' . $column . ' FROM products WHERE product_group_id = ' . (int)$groupId . ' AND ' . $column . ' IS NOT NULL GROUP BY ' . $column . ' ORDER BY COUNT(*) DESC LIMIT 1')->fetchColumn();
			return $value !== false ? (int)$value : null;
		};

		return ['location_id' => $most('location_id'), 'qu_id' => $most('qu_id_stock')];
	}

	private function Presets(array $options): array
	{
		$settings = UsersService::GetInstance()->GetUserSettings(GROCY_USER_ID);
		$location = (int)($settings['product_presets_location_id'] ?? -1);
		$qu = (int)($settings['product_presets_qu_id'] ?? -1);
		$group = (int)($settings['product_presets_product_group_id'] ?? -1);

		return [
			'product_group_id' => $group > 0 ? $group : null,
			'location_id' => $location > 0 ? $location : ($options['locations'][0]['id'] ?? null),
			'qu_id' => $qu > 0 ? $qu : ($options['quantity_units'][0]['id'] ?? null)
		];
	}

	// What was chosen last time for an Open Facts category, per profile
	private static function CategoryMapPath(): string
	{
		return GROCY_PROFILE_DATAPATH . '/' . self::CATEGORY_MAP_FILE;
	}

	private static function CategoryMap(): array
	{
		if (!file_exists(self::CategoryMapPath()))
		{
			return [];
		}

		$map = json_decode((string)file_get_contents(self::CategoryMapPath()), true);
		return is_array($map) ? $map : [];
	}

	private function Learn(array $tags, ?int $groupId, ?int $locationId, ?int $quId): void
	{
		if (count($tags) === 0 || ($groupId === null && $locationId === null))
		{
			return;
		}

		$map = self::CategoryMap();

		// The most specific categories say the most
		foreach (array_slice(array_reverse($tags), 0, 3) as $tag)
		{
			$map[(string)$tag] = ['product_group_id' => $groupId, 'location_id' => $locationId, 'qu_id' => $quId];
		}

		file_put_contents(self::CategoryMapPath(), json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
	}

	/**
	 * Fills in what a source left open: group from what was learned, location
	 * and unit from the group, the rest from the user's presets. Only ids that
	 * exist here survive.
	 */
	private function Complete(array $proposal, array $options): array
	{
		$valid = fn (array $rows, $id) => $id !== null && in_array((int)$id, array_map(fn ($row) => (int)$row['id'], $rows), true) ? (int)$id : null;

		$proposal['product_group_id'] = $valid($options['product_groups'], $proposal['product_group_id'] ?? null);
		$proposal['location_id'] = $valid($options['locations'], $proposal['location_id'] ?? null);
		$proposal['qu_id'] = $valid($options['quantity_units'], $proposal['qu_id'] ?? null);

		if ($proposal['product_group_id'] === null && count($proposal['category_tags'] ?? []) > 0)
		{
			$map = self::CategoryMap();

			foreach (array_reverse($proposal['category_tags']) as $tag)
			{
				if (isset($map[$tag]))
				{
					$proposal['product_group_id'] = $valid($options['product_groups'], $map[$tag]['product_group_id'] ?? null);
					$proposal['location_id'] ??= $valid($options['locations'], $map[$tag]['location_id'] ?? null);
					$proposal['qu_id'] ??= $valid($options['quantity_units'], $map[$tag]['qu_id'] ?? null);
					$proposal['learned'] = true;
					break;
				}
			}
		}

		$typical = $this->TypicalForGroup($proposal['product_group_id']);
		$proposal['location_id'] ??= $typical['location_id'];
		$proposal['qu_id'] ??= $typical['qu_id'];

		$presets = $this->Presets($options);
		if (empty($proposal['default_best_before_days']))
		{
			$proposal['default_best_before_days'] = max(0, (int)(UsersService::GetInstance()->GetUserSettings(GROCY_USER_ID)['product_presets_default_due_days'] ?? 0));
		}
		$proposal['product_group_id'] ??= $valid($options['product_groups'], $presets['product_group_id']);
		$proposal['location_id'] ??= $valid($options['locations'], $presets['location_id']);
		$proposal['qu_id'] ??= $valid($options['quantity_units'], $presets['qu_id']);

		// A product of this name exists already - more likely a second barcode
		// for it than a new product
		$existing = $this->DB->products()->where('name = :1 COLLATE NOCASE', $proposal['name'])->fetch();
		$proposal['existing_product'] = $existing !== null ? ['id' => (int)$existing->id, 'name' => $existing->name] : null;

		return $proposal;
	}

	// --- source 1: the other profiles ------------------------------------------

	private function LookupInProfiles(string $barcode, array $options): ?array
	{
		foreach (Profiles::All() as $profile)
		{
			if ($profile['id'] === GROCY_PROFILE_ID)
			{
				continue;
			}

			$dbPath = DatabaseService::DbFilePathFor(Profiles::DataPath($profile['id']));

			if (!file_exists($dbPath))
			{
				continue;
			}

			try
			{
				$pdo = new \PDO('sqlite:' . $dbPath);
				$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
				$statement = $pdo->prepare('
					SELECT p.name, p.description, p.picture_file_name, p.default_best_before_days,
						pg.name AS group_name, l.name AS location_name, qu.name AS qu_name
					FROM product_barcodes b
					JOIN products p ON p.id = b.product_id
					LEFT JOIN product_groups pg ON pg.id = p.product_group_id
					LEFT JOIN locations l ON l.id = p.location_id
					LEFT JOIN quantity_units qu ON qu.id = p.qu_id_stock
					WHERE b.barcode = ?
					LIMIT 1');
				$statement->execute([$barcode]);
				$row = $statement->fetch(\PDO::FETCH_ASSOC);
			}
			catch (\Exception $ex)
			{
				continue;
			}

			if ($row === false)
			{
				continue;
			}

			return [
				'source' => 'profile',
				'source_label' => $this->T('Profile "%s"', $profile['name'] ?: $this->T('Main profile')),
				'name' => $row['name'],
				'brand' => null,
				'package_size' => null,
				'description' => $row['description'] ?: null,
				'default_best_before_days' => (int)$row['default_best_before_days'],
				// Same names, same things - whatever exists here is taken over
				'product_group_id' => self::IdByName($options['product_groups'], $row['group_name']),
				'location_id' => self::IdByName($options['locations'], $row['location_name']),
				'qu_id' => self::IdByName($options['quantity_units'], $row['qu_name'], ['name', 'name_plural']),
				'picture' => !empty($row['picture_file_name']) ? ['profile' => $profile['id'], 'file' => basename($row['picture_file_name'])] : null,
				'image_url' => !empty($row['picture_file_name']) ? 'profile' : null,
				'category_tags' => []
			];
		}

		return null;
	}

	// --- source 2: the Open Facts databases ------------------------------------

	private static function OpenFactsHosts(): array
	{
		$configured = defined('GROCY_BARCODE_LOOKUP_OPENFACTS_URLS') ? trim((string)GROCY_BARCODE_LOOKUP_OPENFACTS_URLS) : '';

		if ($configured === '')
		{
			return self::OPEN_FACTS;
		}

		$hosts = [];
		foreach (array_filter(array_map('trim', explode(',', $configured))) as $url)
		{
			$hosts[rtrim($url, '/')] = self::OPEN_FACTS[rtrim($url, '/')] ?? parse_url($url, PHP_URL_HOST);
		}

		return $hosts;
	}

	private function LookupInOpenFacts(string $barcode, array $options): ?array
	{
		$lang = self::Language();
		$fields = implode(',', ['product_name', 'product_name_' . $lang, 'generic_name', 'generic_name_' . $lang, 'brands', 'quantity', 'categories_tags', 'image_front_url', 'image_url', 'product_type']);
		$client = new Client([
			'http_errors' => false,
			'timeout' => 8,
			'connect_timeout' => 4,
			'headers' => ['User-Agent' => 'GrocyModern/1.0 (household inventory; https://github.com/chrisi0801/grocy-modern)']
		]);

		// The first one is asked for every kind of product at once; the others
		// only matter if that universal lookup misses something
		foreach (self::OpenFactsHosts() as $host => $label)
		{
			try
			{
				$response = $client->request('GET', $host . '/api/v2/product/' . rawurlencode($barcode) . '?product_type=all&lc=' . $lang . '&fields=' . $fields);
			}
			catch (\Exception $ex)
			{
				continue;
			}

			if ($response->getStatusCode() !== 200)
			{
				continue;
			}

			$data = json_decode(mb_convert_encoding((string)$response->getBody(), 'UTF-8', 'UTF-8'), true);

			if (!is_array($data) || (int)($data['status'] ?? 0) !== 1 || empty($data['product']))
			{
				continue;
			}

			$product = $data['product'];
			$name = trim((string)($product['product_name_' . $lang] ?? '')) ?: trim((string)($product['product_name'] ?? ''));
			$name = $name !== '' ? $name : trim((string)($product['generic_name_' . $lang] ?? ($product['generic_name'] ?? '')));

			if ($name === '')
			{
				continue;
			}

			// "Soft 3-lagig" says little without "Zewa"
			$brand = trim(explode(',', (string)($product['brands'] ?? ''))[0]);
			if ($brand !== '' && mb_stripos($name, $brand) === false)
			{
				$name = $brand . ' ' . $name;
			}

			$packageSize = trim((string)($product['quantity'] ?? '')) ?: null;
			// The universal lookup answers from whichever database has it
			$sourceLabel = [
				'food' => 'Open Food Facts',
				'beauty' => 'Open Beauty Facts',
				'product' => 'Open Products Facts',
				'petfood' => 'Open Pet Food Facts'
			][$product['product_type'] ?? ''] ?? $label;

			return [
				'source' => 'openfacts',
				'source_label' => $sourceLabel,
				'name' => mb_substr($name, 0, 200),
				'brand' => $brand !== '' ? $brand : null,
				'package_size' => $packageSize,
				'description' => null,
				'default_best_before_days' => 0,
				'product_group_id' => null,
				'location_id' => null,
				// "8 Rollen": if there is a unit "Rolle", that is the one
				'qu_id' => $packageSize !== null ? $this->UnitInText($packageSize, $options) : null,
				'picture' => null,
				'image_url' => ($product['image_front_url'] ?? null) ?: (($product['image_url'] ?? null) ?: null),
				'category_tags' => array_values(array_filter((array)($product['categories_tags'] ?? []), 'is_string'))
			];
		}

		return null;
	}

	private function UnitInText(string $text, array $options): ?int
	{
		foreach ($options['quantity_units'] as $unit)
		{
			foreach (['name_plural', 'name'] as $field)
			{
				$word = trim((string)($unit[$field] ?? ''));

				if (mb_strlen($word) >= 3 && preg_match('/(^|[^\p{L}])' . preg_quote($word, '/') . '($|[^\p{L}])/iu', $text))
				{
					return (int)$unit['id'];
				}
			}
		}

		return null;
	}

	/**
	 * The free sources, in order. Returns the proposal (or null) plus what
	 * the paid one could still do.
	 */
	public function Lookup(string $barcode): array
	{
		$barcode = self::NormalizeBarcode($barcode);
		if ($barcode === '')
		{
			throw new \Exception($this->T('This is not a barcode'));
		}

		$options = $this->Options();
		$proposal = $this->LookupInProfiles($barcode, $options) ?? $this->LookupInOpenFacts($barcode, $options);

		if ($proposal !== null)
		{
			$proposal['barcode'] = $barcode;
			$proposal = $this->Complete($proposal, $options);
		}

		return ['barcode' => $barcode, 'result' => $proposal, 'options' => $options, 'ai' => $this->AiStatus()];
	}

	// --- source 3: Claude with web search ----------------------------------------

	private function AiPrompt(string $barcode, array $options): string
	{
		$list = fn (array $rows) => count($rows) > 0 ? implode(', ', array_map(fn ($row) => '"' . $row['name'] . '"', $rows)) : '(none)';

		return 'Identify the retail product with the barcode (EAN/GTIN) ' . $barcode . '. It was just scanned in a private household in ' . GROCY_LOCALE . ' and is to be added to the household\'s stock list.' . "\n\n"
			. 'Search the web for this exact barcode number - shop listings and product databases often show it. Only report a product when a source actually connects it to this barcode; a product that merely looks plausible is not a match.' . "\n\n"
			. 'Report the result with the report_product tool, exactly once. Write the name the way someone would put it on a shopping list in the household\'s language: brand and product, without marketing words (for example "Zewa Toilettenpapier Soft 3-lagig").' . "\n\n"
			. 'For product_group, location and quantity_unit choose the best fitting entry of these lists, or leave the field empty when none fits:' . "\n"
			. '- product groups: ' . $list($options['product_groups']) . "\n"
			. '- locations in the household: ' . $list($options['locations']) . "\n"
			. '- quantity units (how the product is counted in stock): ' . $list($options['quantity_units']);
	}

	private function AiTool(array $options): array
	{
		$choice = function (array $rows, string $description)
		{
			$schema = ['type' => 'string', 'description' => $description . ' Empty string when none fits.'];
			if (count($rows) > 0)
			{
				$schema['enum'] = array_merge(array_map(fn ($row) => (string)$row['name'], $rows), ['']);
			}
			return $schema;
		};

		return [
			'name' => 'report_product',
			'description' => 'Report the identified product - or found=false when the barcode could not be matched to a product with confidence.',
			'strict' => true,
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'found' => ['type' => 'boolean'],
					'name' => ['type' => 'string', 'description' => 'Shopping list name: brand and product, in the household\'s language. Empty when not found.'],
					'brand' => ['type' => 'string'],
					'package_size' => ['type' => 'string', 'description' => 'As printed, e.g. "8 Rollen à 150 Blatt" or "500 g". Empty when unknown.'],
					'product_group' => $choice($options['product_groups'], 'Best fitting product group.'),
					'location' => $choice($options['locations'], 'Where in the household this is usually kept.'),
					'quantity_unit' => $choice($options['quantity_units'], 'How it is counted in stock.'),
					'source_url' => ['type' => 'string', 'description' => 'The page that connects the barcode to the product.'],
					'confidence' => ['type' => 'string', 'enum' => ['high', 'medium', 'low']]
				],
				'required' => ['found', 'name', 'brand', 'package_size', 'product_group', 'location', 'quantity_unit', 'source_url', 'confidence'],
				'additionalProperties' => false
			]
		];
	}

	// Counts against the monthly limit, and keeps the numbers for the
	// settings page; under a lock, several lookups may end at once
	private function RecordUsage(int $inputTokens, int $outputTokens, int $searches): void
	{
		$handle = fopen(self::SettingsPath() . '.lock', 'c');
		flock($handle, LOCK_EX);

		$settings = self::Settings();
		$month = date('Y-m');
		$usage = $settings['usage'][$month] ?? ['lookups' => 0, 'searches' => 0, 'input_tokens' => 0, 'output_tokens' => 0];
		$usage['lookups']++;
		$usage['searches'] += $searches;
		$usage['input_tokens'] += $inputTokens;
		$usage['output_tokens'] += $outputTokens;
		$settings['usage'][$month] = $usage;
		self::SaveSettings($settings);

		flock($handle, LOCK_UN);
		fclose($handle);
	}

	public static function EstimatedCost(array $usage): float
	{
		return round(($usage['input_tokens'] ?? 0) / 1000000 * self::AI_PRICE_INPUT_PER_MTOK
			+ ($usage['output_tokens'] ?? 0) / 1000000 * self::AI_PRICE_OUTPUT_PER_MTOK
			+ ($usage['searches'] ?? 0) * self::AI_PRICE_PER_SEARCH, 2);
	}

	public function LookupWithAi(string $barcode): array
	{
		$barcode = self::NormalizeBarcode($barcode);
		if ($barcode === '')
		{
			throw new \Exception($this->T('This is not a barcode'));
		}

		$status = $this->AiStatus();
		if (!$status['available'])
		{
			throw new \Exception($status['reason'] === 'limit'
				? $this->T('The monthly limit for web searches with AI is reached')
				: $this->T('The web search with AI is not set up'));
		}

		// A web search takes its time - more than PHP's default allows
		@set_time_limit(180);

		$options = $this->Options();
		$client = new AnthropicClient(apiKey: self::ApiKey(self::Settings()), requestOptions: ['timeout' => 150, 'maxRetries' => 1]);
		$messages = [['role' => 'user', 'content' => $this->AiPrompt($barcode, $options)]];
		$tools = [
			// Array keys are the SDK's property names (camelCase), not the wire names
			['type' => 'web_search_20260209', 'name' => 'web_search', 'maxUses' => self::AI_MAX_SEARCHES],
			$this->AiTool($options)
		];

		$report = null;
		$inputTokens = 0;
		$outputTokens = 0;
		$searches = 0;

		try
		{
			// A long server-side search may pause the turn; it continues with
			// the same request plus what came back so far
			for ($round = 0; $round < 3 && $report === null; $round++)
			{
				$response = $client->beta->messages->create(
					model: self::AI_MODEL,
					maxTokens: 16000,
					messages: $messages,
					tools: $tools,
					outputConfig: ['effort' => 'medium'],
					// Should a safety classifier decline the request, the API
					// re-runs it on the recommended fallback model by itself
					fallbacks: 'default',
					betas: ['server-side-fallback-2026-07-01']
				);

				$inputTokens += (int)$response->usage->inputTokens + (int)($response->usage->cacheReadInputTokens ?? 0) + (int)($response->usage->cacheCreationInputTokens ?? 0);
				$outputTokens += (int)$response->usage->outputTokens;
				$searches += (int)($response->usage->serverToolUse->webSearchRequests ?? 0);

				if ($response->stopReason === 'refusal')
				{
					break;
				}

				foreach ($response->content as $block)
				{
					if ($block->type === 'tool_use' && $block->name === 'report_product')
					{
						$report = $block->input;
					}
				}

				if ($report !== null || $response->stopReason !== 'pause_turn')
				{
					break;
				}

				$messages[] = ['role' => 'assistant', 'content' => json_decode(json_encode($response->content), true)];
			}
		}
		catch (AuthenticationException $ex)
		{
			throw new \Exception($this->T('The API key was not accepted - please check it in the barcode lookup settings'));
		}
		catch (RateLimitException $ex)
		{
			throw new \Exception($this->T('Too many requests to the AI right now - please try again in a minute'));
		}
		catch (APIException $ex)
		{
			throw new \Exception($this->T('The web search with AI failed: %s', $ex->getMessage()));
		}
		finally
		{
			if ($inputTokens > 0 || $outputTokens > 0)
			{
				$this->RecordUsage($inputTokens, $outputTokens, $searches);
			}
		}

		$result = null;

		if (is_array($report) && !empty($report['found']) && trim((string)($report['name'] ?? '')) !== '')
		{
			$result = $this->Complete([
				'barcode' => $barcode,
				'source' => 'ai',
				'source_label' => $this->T('Web search with AI'),
				'name' => mb_substr(trim((string)$report['name']), 0, 200),
				'brand' => trim((string)($report['brand'] ?? '')) ?: null,
				'package_size' => trim((string)($report['package_size'] ?? '')) ?: null,
				'description' => null,
				'default_best_before_days' => 0,
				'product_group_id' => self::IdByName($options['product_groups'], $report['product_group'] ?? null),
				'location_id' => self::IdByName($options['locations'], $report['location'] ?? null),
				'qu_id' => self::IdByName($options['quantity_units'], $report['quantity_unit'] ?? null, ['name', 'name_plural']),
				'picture' => null,
				'image_url' => null,
				'category_tags' => [],
				'source_url' => preg_match('/^https?:\/\//', (string)($report['source_url'] ?? '')) ? (string)$report['source_url'] : null,
				'confidence' => in_array($report['confidence'] ?? '', ['high', 'medium', 'low'], true) ? $report['confidence'] : 'low'
			], $options);
		}

		return ['barcode' => $barcode, 'result' => $result, 'options' => $options, 'ai' => $this->AiStatus()];
	}

	// --- taking a proposal over ----------------------------------------------------

	/**
	 * Creates the product from the confirmed preview: the product, its
	 * barcode and - where there is one - its picture.
	 */
	public function CreateProduct(array $data): int
	{
		$barcode = self::NormalizeBarcode((string)($data['barcode'] ?? ''));
		$name = trim((string)($data['name'] ?? ''));

		if ($barcode === '' || $name === '')
		{
			throw new \Exception($this->T('A name is required'));
		}

		if ($this->DB->products()->where('name = :1 COLLATE NOCASE', $name)->fetch() !== null)
		{
			throw new \Exception($this->T('There already is a product with this name'));
		}

		if ($this->DB->product_barcodes()->where('barcode = :1', $barcode)->fetch() !== null)
		{
			throw new \Exception($this->T('This barcode is already assigned to a product'));
		}

		$options = $this->Options();
		$valid = fn (array $rows, $id) => $id !== null && $id !== '' && in_array((int)$id, array_map(fn ($row) => (int)$row['id'], $rows), true) ? (int)$id : null;
		$quId = $valid($options['quantity_units'], $data['qu_id'] ?? null);
		$locationId = $valid($options['locations'], $data['location_id'] ?? null);
		$groupId = $valid($options['product_groups'], $data['product_group_id'] ?? null);

		if ($quId === null || $locationId === null)
		{
			throw new \Exception($this->T('Please choose a location and a quantity unit'));
		}

		$description = trim((string)($data['package_size'] ?? ''));

		$product = $this->DB->products()->createRow([
			'name' => $name,
			'description' => $description !== '' ? $description : null,
			'location_id' => $locationId,
			'product_group_id' => $groupId,
			'qu_id_purchase' => $quId,
			'qu_id_stock' => $quId,
			'qu_id_consume' => $quId,
			'qu_id_price' => $quId,
			'default_best_before_days' => max(0, (int)($data['default_best_before_days'] ?? 0))
		]);
		$product->save();
		$productId = (int)$product->id;

		$this->DB->product_barcodes()->createRow([
			'product_id' => $productId,
			'barcode' => $barcode
		])->save();

		$picture = $this->SavePicture($barcode, $data);
		if ($picture !== null)
		{
			$this->DB->products($productId)->update(['picture_file_name' => $picture]);
		}

		$this->Learn(array_values(array_filter((array)($data['category_tags'] ?? []), 'is_string')), $groupId, $locationId, $quId);

		return $productId;
	}

	// A picture from Open Facts is downloaded, one from another profile copied
	private function SavePicture(string $barcode, array $data): ?string
	{
		$files = FilesService::GetInstance();

		try
		{
			if (!empty($data['picture_profile']) && !empty($data['picture_file']))
			{
				$profileId = (string)$data['picture_profile'];
				$file = basename((string)$data['picture_file']);

				if (Profiles::Get($profileId) === null || $profileId === GROCY_PROFILE_ID)
				{
					return null;
				}

				$source = ProfileService::StoragePathFor(Profiles::DataPath($profileId)) . '/productpictures/' . $file;
				if (!is_file($source))
				{
					return null;
				}

				$target = $barcode . '.' . strtolower(pathinfo($file, PATHINFO_EXTENSION) ?: 'jpg');
				copy($source, $files->GetFilePath('productpictures', $target));
				return $target;
			}

			$url = (string)($data['image_url'] ?? '');
			if (!preg_match('/^https:\/\//', $url))
			{
				return null;
			}

			$response = (new Client(['http_errors' => false, 'timeout' => 10, 'headers' => ['User-Agent' => 'GrocyModern/1.0 (household inventory)']]))->request('GET', $url);
			$type = strtolower($response->getHeaderLine('Content-Type'));

			if ($response->getStatusCode() !== 200 || !str_starts_with($type, 'image/'))
			{
				return null;
			}

			$body = (string)$response->getBody();
			if (strlen($body) === 0 || strlen($body) > 8 * 1024 * 1024)
			{
				return null;
			}

			$extension = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'][explode(';', $type)[0]] ?? 'jpg';
			$target = $barcode . '.' . $extension;
			file_put_contents($files->GetFilePath('productpictures', $target), $body);
			return $target;
		}
		catch (\Exception $ex)
		{
			// The product is there - a missing picture is no reason to fail
			return null;
		}
	}
}
