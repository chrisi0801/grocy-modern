<?php

namespace Grocy\Helpers;

use Grocy\Services\DatabaseService;

/**
 * Profiles (Grocy Modern): several completely separate households in one
 * installation - one per building, for example.
 *
 * A profile is nothing but a data directory of its own, with its own
 * database and its own uploaded files. Grocy itself does not know about
 * profiles at all: every request is simply pointed at the database of one of
 * them. That keeps stock, chores, calendar, shopping lists, budget, users -
 * everything - apart without touching Grocy's data model.
 *
 * The existing data directory is the main profile ("default"); further
 * profiles live in data/profiles/<id>/. config.php, the view cache, plugins
 * and custom CSS/JS stay shared. The list of profiles is data/profiles.json.
 *
 * Which profile a request is for, in this order:
 *   1. the X-Grocy-Profile header - every API request of a page carries the
 *      profile the page was rendered for, so a second tab switched to another
 *      profile can never make this one change the wrong household
 *   2. the API key - integrations (Home Assistant, ...) need nothing but the
 *      key of the profile they belong to
 *   3. the grocy_profile cookie - the profile last switched to in this browser
 *   4. the main profile
 */
class Profiles
{
	const MAIN = 'default';
	const COOKIE_NAME = 'grocy_profile';
	const HEADER_NAME = 'HTTP_X_GROCY_PROFILE';
	const COLORS = ['#0e7c66', '#2563eb', '#c2410c', '#7c3aed', '#be185d', '#4d7c0f'];

	private static ?array $Registry = null;

	public static function RegistryPath(): string
	{
		return GROCY_DATAPATH . '/profiles.json';
	}

	private static function Registry(): array
	{
		if (self::$Registry === null)
		{
			$registry = [];

			if (file_exists(self::RegistryPath()))
			{
				$registry = json_decode((string)file_get_contents(self::RegistryPath()), true);
			}

			self::$Registry = is_array($registry) ? $registry : [];
			self::$Registry += ['main' => [], 'profiles' => []];
		}

		return self::$Registry;
	}

	public static function SaveRegistry(array $registry): void
	{
		$json = json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

		// Written to a temporary file first, so a crash never leaves half a file
		$temp = self::RegistryPath() . '.tmp';
		file_put_contents($temp, $json, LOCK_EX);
		rename($temp, self::RegistryPath());

		self::$Registry = null;
	}

	public static function RawRegistry(): array
	{
		return self::Registry();
	}

	/**
	 * All profiles, the main one first:
	 * [['id' => ..., 'name' => ..., 'color' => ..., 'is_main' => bool], ...]
	 */
	public static function All(): array
	{
		$registry = self::Registry();
		$main = $registry['main'];

		$profiles = [[
			'id' => self::MAIN,
			'name' => !empty($main['name']) ? $main['name'] : null,
			'color' => !empty($main['color']) ? $main['color'] : self::COLORS[0],
			'is_main' => true,
			'created' => null
		]];

		foreach ($registry['profiles'] as $profile)
		{
			if (empty($profile['id']) || !self::IsValidId($profile['id']))
			{
				continue;
			}

			$profiles[] = [
				'id' => $profile['id'],
				'name' => $profile['name'] ?? $profile['id'],
				'color' => $profile['color'] ?? self::COLORS[0],
				'is_main' => false,
				'created' => $profile['created'] ?? null
			];
		}

		return $profiles;
	}

	public static function Get(string $id): ?array
	{
		foreach (self::All() as $profile)
		{
			if ($profile['id'] === $id)
			{
				return $profile;
			}
		}

		return null;
	}

	// Only a profile next to the main one makes the whole thing visible
	public static function IsInUse(): bool
	{
		return count(self::All()) > 1;
	}

	public static function IsValidId($id): bool
	{
		return is_string($id) && ($id === self::MAIN || preg_match('/^[a-z0-9][a-z0-9-]{0,39}$/', $id) === 1);
	}

	public static function DataPath(string $id): string
	{
		return $id === self::MAIN ? GROCY_DATAPATH : GROCY_DATAPATH . '/profiles/' . $id;
	}

	// The login cookies of the main profile keep Grocy's own names, so
	// nobody gets logged out by the update
	public static function CookieSuffix(string $id): string
	{
		return $id === self::MAIN ? '' : '__' . $id;
	}

	/**
	 * Picks the profile of this request and defines the constants the rest of
	 * the application works with. Runs once, right after the configuration
	 * is loaded and before anything touches a database.
	 */
	public static function Bootstrap(): void
	{
		$id = self::Resolve();
		$profile = self::Get($id);

		define('GROCY_PROFILE_ID', $id);
		define('GROCY_PROFILE_NAME', $profile['name'] ?? '');
		define('GROCY_PROFILE_COLOR', $profile['color'] ?? self::COLORS[0]);
		define('GROCY_PROFILE_DATAPATH', self::DataPath($id));
		define('GROCY_PROFILE_COOKIE_SUFFIX', self::CookieSuffix($id));

		if (!file_exists(GROCY_PROFILE_DATAPATH . '/storage'))
		{
			@mkdir(GROCY_PROFILE_DATAPATH . '/storage', 0777, true);
		}
	}

	private static function Resolve(): string
	{
		if (!self::IsInUse())
		{
			return self::MAIN;
		}

		$header = $_SERVER[self::HEADER_NAME] ?? null;
		if ($header !== null && self::Get($header) !== null)
		{
			return $header;
		}

		$byApiKey = self::ProfileOfApiKey();
		if ($byApiKey !== null)
		{
			return $byApiKey;
		}

		$cookie = $_COOKIE[self::COOKIE_NAME] ?? null;
		if ($cookie !== null && self::Get($cookie) !== null)
		{
			return $cookie;
		}

		return self::MAIN;
	}

	// An API key exists in exactly one profile - the request belongs there
	private static function ProfileOfApiKey(): ?string
	{
		$keys = array_filter([
			$_SERVER['HTTP_GROCY_API_KEY'] ?? null,
			$_GET['GROCY-API-KEY'] ?? null,
			// The shareable iCal link of the calendar
			$_GET['secret'] ?? null
		], fn ($key) => is_string($key) && $key !== '');

		if (count($keys) === 0)
		{
			return null;
		}

		foreach (self::All() as $profile)
		{
			$dbPath = DatabaseService::DbFilePathFor(self::DataPath($profile['id']));

			if (!file_exists($dbPath))
			{
				continue;
			}

			try
			{
				$pdo = new \PDO('sqlite:' . $dbPath);
				$statement = $pdo->prepare('SELECT COUNT(*) FROM api_keys WHERE api_key IN (' . implode(',', array_fill(0, count($keys), '?')) . ')');
				$statement->execute(array_values($keys));

				if ((int)$statement->fetchColumn() > 0)
				{
					return $profile['id'];
				}
			}
			catch (\Exception $ex)
			{
				// A profile that cannot be read cannot own the key
			}
		}

		return null;
	}

	public static function Slug(string $name): string
	{
		$slug = strtolower(trim($name));
		$slug = strtr($slug, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', 'Ä' => 'ae', 'Ö' => 'oe', 'Ü' => 'ue']);
		$slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
		$slug = trim((string)$slug, '-');
		$slug = substr($slug, 0, 30);

		return $slug !== '' && $slug !== self::MAIN ? $slug : 'profile';
	}
}
