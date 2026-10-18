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

	// Claude - the models to choose from, with their published prices (USD per
	// million tokens) at the time of writing, for the costs shown in the
	// settings; the bill is what counts. Haiku has no server-side fallback
	// and no dynamic filtering for the web tools, so it gets the basic ones.
	const AI_MODELS = [
		'claude-opus-5-5' => ['label' => 'Claude Opus 5.5', 'input' => 4.0, 'output' => 20.0, 'cache_read' => 0.20, 'web_tools' => 'current', 'fallbacks' => true],
		'claude-sonnet-5-5' => ['label' => 'Claude Sonnet 5.5', 'input' => 2.0, 'output' => 10.0, 'cache_read' => 0.20, 'web_tools' => 'current', 'fallbacks' => true],
		// Above 100k prompt tokens per request Haiku costs five times as much;
		// its cache reads are assumed at a tenth of the input price
		'claude-haiku-5-5' => ['label' => 'Claude Haiku 5.5', 'input' => 0.10, 'output' => 0.50, 'cache_read' => 0.01, 'long_above' => 100000, 'long_input' => 0.50, 'long_output' => 2.50, 'web_tools' => 'basic', 'fallbacks' => false]
	];
	const DEFAULT_AI_MODEL = 'claude-opus-5-5';
	const DEFAULT_MAX_SEARCHES = 3;
	const AI_MAX_FETCHES = 3;
	const AI_PRICE_PER_SEARCH = 0.01;
	// Used for months recorded before the cost was stored per lookup
	const LEGACY_PRICE_INPUT_PER_MTOK = 4.0;
	const LEGACY_PRICE_OUTPUT_PER_MTOK = 20.0;

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
		$settings += ['ai_enabled' => false, 'api_key' => '', 'monthly_limit' => self::DEFAULT_MONTHLY_LIMIT, 'ai_model' => self::DEFAULT_AI_MODEL, 'max_searches' => self::DEFAULT_MAX_SEARCHES, 'usage' => []];

		if (!isset(self::AI_MODELS[$settings['ai_model']]))
		{
			$settings['ai_model'] = self::DEFAULT_AI_MODEL;
		}

		return $settings;
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
			'model' => $settings['ai_model'],
			'models' => array_map(fn ($id, $model) => ['id' => $id, 'label' => $model['label'], 'input' => $model['input'], 'output' => $model['output']], array_keys(self::AI_MODELS), self::AI_MODELS),
			'max_searches' => (int)$settings['max_searches'],
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
		$settings['max_searches'] = max(1, min(5, (int)($data['max_searches'] ?? self::DEFAULT_MAX_SEARCHES)));

		if (isset($data['ai_model']) && isset(self::AI_MODELS[$data['ai_model']]))
		{
			$settings['ai_model'] = $data['ai_model'];
		}

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

		$settings = self::Settings();
		$key = self::ApiKey($settings);

		if ($key === '')
		{
			return ['ok' => false, 'message' => $this->T('No API key is stored')];
		}

		try
		{
			$client = new AnthropicClient(apiKey: $key, requestOptions: ['timeout' => 15, 'maxRetries' => 1]);
			$model = $client->models->retrieve($settings['ai_model']);
			return ['ok' => true, 'message' => $this->T('The key works - %s is available', $model->displayName ?? $settings['ai_model'])];
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

	// The location, unit and due days most products of a group already have -
	// a new pack of toilet paper goes where the other hygiene products are,
	// and is never overdue like them. Per group, so the preview can follow
	// when the group is changed there.
	private ?array $GroupDefaults = null;

	private function GroupDefaults(): array
	{
		if ($this->GroupDefaults !== null)
		{
			return $this->GroupDefaults;
		}

		$defaults = [];

		foreach (['location_id' => 'location_id', 'qu_id' => 'qu_id_stock', 'default_best_before_days' => 'default_best_before_days'] as $key => $column)
		{
			$rows = DatabaseService::GetInstance()->ExecuteDbQuery('SELECT product_group_id, ' . $column . ' AS value, COUNT(*) AS count FROM products WHERE product_group_id IS NOT NULL AND ' . $column . ' IS NOT NULL GROUP BY product_group_id, ' . $column . ' ORDER BY product_group_id, count DESC')->fetchAll(\PDO::FETCH_ASSOC);

			foreach ($rows as $row)
			{
				$defaults[(int)$row['product_group_id']][$key] ??= (int)$row['value'];
			}
		}

		return $this->GroupDefaults = $defaults;
	}

	private function TypicalForGroup(?int $groupId): array
	{
		return ($this->GroupDefaults()[(int)$groupId] ?? []) + ['location_id' => null, 'qu_id' => null, 'default_best_before_days' => null];
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

	private function Learn(array $tags, ?int $groupId, ?int $locationId, ?int $quId, int $dueDays): void
	{
		if (count($tags) === 0 || ($groupId === null && $locationId === null))
		{
			return;
		}

		$map = self::CategoryMap();

		// The most specific categories say the most
		foreach (array_slice(array_reverse($tags), 0, 3) as $tag)
		{
			$map[(string)$tag] = ['product_group_id' => $groupId, 'location_id' => $locationId, 'qu_id' => $quId, 'default_best_before_days' => $dueDays];
		}

		file_put_contents(self::CategoryMapPath(), json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
	}

	/**
	 * Fills in what a source left open: group from what was learned, location,
	 * unit and due days from the group, the rest from the user's presets. Only
	 * ids that exist here survive.
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
					$proposal['default_best_before_days'] ??= $map[$tag]['default_best_before_days'] ?? null;
					$proposal['learned'] = true;
					break;
				}
			}
		}

		$typical = $this->TypicalForGroup($proposal['product_group_id']);
		$proposal['location_id'] ??= $valid($options['locations'], $typical['location_id']);
		$proposal['qu_id'] ??= $valid($options['quantity_units'], $typical['qu_id']);
		$proposal['default_best_before_days'] ??= $typical['default_best_before_days'];

		$presets = $this->Presets($options);
		$proposal['default_best_before_days'] = max(-1, (int)($proposal['default_best_before_days'] ?? UsersService::GetInstance()->GetUserSettings(GROCY_USER_ID)['product_presets_default_due_days'] ?? 0));
		$proposal['min_stock_amount'] = max(0, (float)($proposal['min_stock_amount'] ?? 0));
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
					SELECT p.name, p.description, p.picture_file_name, p.default_best_before_days, p.min_stock_amount,
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
				'min_stock_amount' => (float)$row['min_stock_amount'],
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
				// Unknown - the product group decides
				'default_best_before_days' => null,
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

		return ['barcode' => $barcode, 'result' => $proposal, 'options' => $options, 'group_defaults' => $this->GroupDefaults(), 'ai' => $this->AiStatus()];
	}

	// --- source 3: Claude with web search ----------------------------------------

	private function AiPrompt(string $barcode, array $options, string $hint): string
	{
		$list = fn (array $rows) => count($rows) > 0 ? implode(', ', array_map(fn ($row) => '"' . $row['name'] . '"', $rows)) : '(none)';

		$prompt = 'Identify the retail product with the barcode (EAN/GTIN) ' . $barcode . '. It was just scanned in a private household in ' . GROCY_LOCALE . ' and is to be added to the household\'s stock list.' . "\n\n"
			. 'How to search: search for the bare number first, then for "EAN ' . $barcode . '". Local online shops, drugstores and barcode databases often list the EAN on the product page even when the search snippet does not show it - open the most promising page with web_fetch to check. A barcode database you can also open directly: https://www.ean-search.org/?q=' . $barcode . ' (if it cannot be fetched, go on with the search).' . "\n\n";

		if ($hint !== '')
		{
			$prompt .= 'The person who scanned it read this on the package: "' . $hint . '". Use it to search for the product, and check whether a source lists this barcode for it. If the product fits the hint but no source confirms the barcode, you may still report it, with confidence "low", and say so in the note.' . "\n\n";
		}

		return $prompt
			. 'Only report a product when a source connects it to this barcode' . ($hint !== '' ? ' or it clearly fits the hint' : '') . '; a product that merely looks plausible is not a match.' . "\n\n"
			. 'Report the result with the report_product tool, exactly once - also when nothing was found. Write the name the way someone would put it on a shopping list in the household\'s language: brand and product, without marketing words (for example "Zewa Toilettenpapier Soft 3-lagig").' . "\n\n"
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
					'confidence' => ['type' => 'string', 'enum' => ['high', 'medium', 'low']],
					'note' => ['type' => 'string', 'description' => 'One or two short sentences for the household, in its language: where the product was found - or, when nothing matched, what was searched and what came up instead.']
				],
				'required' => ['found', 'name', 'brand', 'package_size', 'product_group', 'location', 'quantity_unit', 'source_url', 'confidence', 'note'],
				'additionalProperties' => false
			]
		];
	}

	// Tokens and searches of one response, priced for the model it was meant for
	private static function AddUsage(array &$usage, $response, array $model): void
	{
		$input = (int)$response->usage->inputTokens;
		$cacheRead = (int)($response->usage->cacheReadInputTokens ?? 0);
		$cacheWrite = (int)($response->usage->cacheCreationInputTokens ?? 0);
		$output = (int)$response->usage->outputTokens;
		$searches = (int)($response->usage->serverToolUse->webSearchRequests ?? 0);

		$long = isset($model['long_above']) && $input + $cacheRead + $cacheWrite > $model['long_above'];
		$inputPrice = $long ? $model['long_input'] : $model['input'];
		$outputPrice = $long ? $model['long_output'] : $model['output'];

		$usage['input_tokens'] += $input + $cacheRead + $cacheWrite;
		$usage['output_tokens'] += $output;
		$usage['searches'] += $searches;
		$usage['cost'] += ($input * $inputPrice + $cacheWrite * $inputPrice * 1.25 + $cacheRead * $model['cache_read'] + $output * $outputPrice) / 1000000
			+ $searches * self::AI_PRICE_PER_SEARCH;
	}

	// Counts against the monthly limit, and keeps the numbers for the
	// settings page; under a lock, several lookups may end at once
	private function RecordUsage(array $lookup): void
	{
		$handle = fopen(self::SettingsPath() . '.lock', 'c');
		flock($handle, LOCK_EX);

		$settings = self::Settings();
		$month = date('Y-m');
		$usage = $settings['usage'][$month] ?? ['lookups' => 0, 'searches' => 0, 'input_tokens' => 0, 'output_tokens' => 0];
		// Lookups recorded before the cost was kept per lookup are estimated once
		$usage['cost'] ??= self::LegacyCost($usage);
		$usage['lookups']++;
		$usage['searches'] += $lookup['searches'];
		$usage['input_tokens'] += $lookup['input_tokens'];
		$usage['output_tokens'] += $lookup['output_tokens'];
		$usage['cost'] = round($usage['cost'] + $lookup['cost'], 6);
		$settings['usage'][$month] = $usage;
		self::SaveSettings($settings);

		flock($handle, LOCK_UN);
		fclose($handle);
	}

	private static function LegacyCost(array $usage): float
	{
		return ($usage['input_tokens'] ?? 0) / 1000000 * self::LEGACY_PRICE_INPUT_PER_MTOK
			+ ($usage['output_tokens'] ?? 0) / 1000000 * self::LEGACY_PRICE_OUTPUT_PER_MTOK
			+ ($usage['searches'] ?? 0) * self::AI_PRICE_PER_SEARCH;
	}

	public static function TotalCost(array $usage): float
	{
		return (float)($usage['cost'] ?? self::LegacyCost($usage));
	}

	public static function EstimatedCost(array $usage): float
	{
		return round(self::TotalCost($usage), 2);
	}

	public function LookupWithAi(string $barcode, string $hint = ''): array
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

		$settings = self::Settings();
		$modelId = $settings['ai_model'];
		$model = self::AI_MODELS[$modelId];
		$hint = mb_substr(trim(preg_replace('/\s+/u', ' ', $hint)), 0, 200);

		// Searching and opening pages takes its time - more than PHP's default allows
		@set_time_limit(300);

		$options = $this->Options();
		$client = new AnthropicClient(apiKey: self::ApiKey($settings), requestOptions: ['timeout' => 150, 'maxRetries' => 1]);
		$current = $model['web_tools'] === 'current';
		$request = [
			'model' => $modelId,
			'maxTokens' => 16000,
			'messages' => [['role' => 'user', 'content' => $this->AiPrompt($barcode, $options, $hint)]],
			// Array keys are the SDK's property names (camelCase), not the wire names
			'tools' => [
				['type' => $current ? 'web_search_20260209' : 'web_search_20250305', 'name' => 'web_search', 'maxUses' => (int)$settings['max_searches']],
				// Opening a shop page shows whether it really lists this barcode;
				// a page can be long, so it is cut
				['type' => $current ? 'web_fetch_20260209' : 'web_fetch_20250910', 'name' => 'web_fetch', 'maxUses' => self::AI_MAX_FETCHES, 'maxContentTokens' => 8000],
				$this->AiTool($options)
			],
			'outputConfig' => ['effort' => 'medium']
		];

		if ($model['fallbacks'])
		{
			// Should a safety classifier decline the request, the API re-runs
			// it on the recommended fallback model by itself
			$request['fallbacks'] = 'default';
			$request['betas'] = ['server-side-fallback-2026-07-01'];
		}

		$report = null;
		$problem = null;
		$askedForReport = false;
		$usage = ['input_tokens' => 0, 'output_tokens' => 0, 'searches' => 0, 'cost' => 0.0];

		try
		{
			for ($round = 0; $round < 5; $round++)
			{
				$response = $client->beta->messages->create(...$request);
				self::AddUsage($usage, $response, $model);

				if ($response->stopReason === 'refusal')
				{
					$problem = $this->T('Claude declined this request');
					break;
				}

				foreach ($response->content as $block)
				{
					if ($block->type === 'tool_use' && $block->name === 'report_product')
					{
						$report = $block->input;
					}
				}

				if ($report !== null)
				{
					break;
				}

				if ($response->stopReason === 'max_tokens')
				{
					$problem = $this->T('The answer of the AI was cut off');
					break;
				}

				$request['messages'][] = ['role' => 'assistant', 'content' => json_decode(json_encode($response->content), true)];

				// A long server-side search may pause the turn; it continues
				// with the same request plus what came back so far
				if ($response->stopReason === 'pause_turn')
				{
					continue;
				}

				// Done without reporting - asked once more, it usually does
				if ($askedForReport)
				{
					$problem = $this->T('The AI ended its search without a result');
					break;
				}

				$request['messages'][] = ['role' => 'user', 'content' => 'Please report your result now with the report_product tool - found=false if no source connects a product to this barcode.'];
				$askedForReport = true;
			}

			if ($report === null && $problem === null)
			{
				$problem = $this->T('The web search took too long and was stopped');
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
			if ($usage['input_tokens'] > 0 || $usage['output_tokens'] > 0)
			{
				$this->RecordUsage($usage);
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
				// Unknown - the product group decides
				'default_best_before_days' => null,
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

		return [
			'barcode' => $barcode,
			'result' => $result,
			'options' => $options,
			'group_defaults' => $this->GroupDefaults(),
			'ai' => $this->AiStatus(),
			// What Claude says about its search, or why there is no answer
			'ai_note' => $problem ?? (is_array($report) ? (mb_substr(trim((string)($report['note'] ?? '')), 0, 500) ?: null) : null),
			'ai_problem' => $problem !== null,
			'ai_cost' => round($usage['cost'], 4),
			'ai_model' => $model['label']
		];
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
		$dueDays = max(-1, min(99999, (int)($data['default_best_before_days'] ?? 0)));
		$minStock = max(0, min(999999, round((float)($data['min_stock_amount'] ?? 0), 4)));

		$product = $this->DB->products()->createRow([
			'name' => $name,
			'description' => $description !== '' ? $description : null,
			'location_id' => $locationId,
			'product_group_id' => $groupId,
			'qu_id_purchase' => $quId,
			'qu_id_stock' => $quId,
			'qu_id_consume' => $quId,
			'qu_id_price' => $quId,
			// -1: never overdue, 0: the date is asked for on purchase
			'default_best_before_days' => $dueDays,
			'min_stock_amount' => $minStock
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

		$this->Learn(array_values(array_filter((array)($data['category_tags'] ?? []), 'is_string')), $groupId, $locationId, $quId, $dueDays);

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
