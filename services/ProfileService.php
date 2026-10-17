<?php

namespace Grocy\Services;

use Grocy\Controllers\Users\User;
use Grocy\Helpers\Profiles;

/**
 * Profiles (Grocy Modern): setting them up, renaming and removing them.
 * Which profile a request is for is decided in Grocy\Helpers\Profiles.
 */
class ProfileService extends BaseService
{
	// What "master data" means when a new profile takes it over - everything
	// needed to buy and stock the same products, nothing that is a booking
	const MASTER_DATA_TABLES = ['quantity_units', 'product_groups', 'locations', 'shopping_locations', 'products', 'product_barcodes', 'quantity_unit_conversions'];

	private function T(string $text, ...$placeholders): string
	{
		return LocalizationService::GetInstance()->__t($text, ...$placeholders);
	}

	// Profiles are managed from the main profile, by its administrators: an
	// administrator of one household must not be able to remove the other
	public function CanManage(): bool
	{
		return GROCY_PROFILE_ID === Profiles::MAIN && User::HasPermissions(User::PERMISSION_ADMIN);
	}

	private function AssertCanManage(): void
	{
		if (!$this->CanManage())
		{
			throw new ProfilePermissionException($this->T('Profiles can only be managed by an administrator of the main profile'));
		}
	}

	private function ValidName(string $name, ?string $exceptId = null): string
	{
		$name = trim($name);

		if ($name === '')
		{
			throw new \Exception($this->T('A name is required'));
		}

		if (mb_strlen($name) > 40)
		{
			throw new \Exception($this->T('The name can be at most %s characters long', 40));
		}

		foreach (Profiles::All() as $profile)
		{
			if ($profile['id'] !== $exceptId && mb_strtolower((string)$profile['name']) === mb_strtolower($name))
			{
				throw new \Exception($this->T('There already is a profile with this name'));
			}
		}

		return $name;
	}

	private static function ValidColor(?string $color): string
	{
		return in_array($color, Profiles::COLORS, true) ? $color : Profiles::COLORS[0];
	}

	public function GetProfiles(): array
	{
		$profiles = Profiles::All();

		foreach ($profiles as &$profile)
		{
			$profile['is_current'] = $profile['id'] === GROCY_PROFILE_ID;
		}

		return $profiles;
	}

	/**
	 * A new, empty household - optionally with the master data of the main
	 * profile. Whoever creates it becomes its administrator, with the same
	 * user name and password, and is logged in there right away.
	 */
	public function CreateProfile(string $name, ?string $color, bool $copyMasterData): array
	{
		$this->AssertCanManage();

		$name = $this->ValidName($name);
		$color = self::ValidColor($color);

		$ids = array_column(Profiles::All(), 'id');
		$id = Profiles::Slug($name);
		$base = $id;
		for ($i = 2; in_array($id, $ids, true) || file_exists(Profiles::DataPath($id)); $i++)
		{
			$id = $base . '-' . $i;
		}

		$dataPath = Profiles::DataPath($id);
		if (!@mkdir($dataPath . '/storage', 0777, true))
		{
			throw new \Exception($this->T('The folder for the new profile could not be created'));
		}

		$sourceDb = DatabaseService::DbFilePathFor(GROCY_PROFILE_DATAPATH);
		$creator = $this->DB->users(GROCY_USER_ID);
		$withRememberMe = isset($_COOKIE[SessionService::SESSION_TOKEN_COOKIE_NAME_REMEMBER_ME]);

		$services = BaseService::SwapInstances([]);
		DatabaseService::UseDatabaseFile(DatabaseService::DbFilePathFor($dataPath));

		try
		{
			DatabaseMigrationService::GetInstance()->MigrateDatabase();
			$pdo = DatabaseService::GetInstance()->GetDbConnectionRaw();

			if ($copyMasterData)
			{
				$this->CopyMasterData($pdo, $sourceDb);
			}

			$userId = $this->SetUpCreator($pdo, $creator);
			$tokens = $this->CreateLogin($pdo, $userId, $withRememberMe);
		}
		catch (\Throwable $ex)
		{
			DatabaseService::UseDatabaseFile(null);
			BaseService::SwapInstances($services);
			$this->RemoveFolder($dataPath);
			throw $ex;
		}

		DatabaseService::UseDatabaseFile(null);
		BaseService::SwapInstances($services);

		// Migrated for exactly this code - no redirect to the root route on
		// the first visit
		file_put_contents($dataPath . '/migrated.txt', GROCY_CODE_HASH);

		if ($copyMasterData)
		{
			$this->CopyProductPictures($dataPath);
		}

		$registry = Profiles::RawRegistry();
		$registry['profiles'][] = [
			'id' => $id,
			'name' => $name,
			'color' => $color,
			'created' => date('Y-m-d H:i:s')
		];
		Profiles::SaveRegistry($registry);

		// Logged in there already - switching over needs no second login
		$suffix = Profiles::CookieSuffix($id);
		$this->SetCookie('grocy_session_access_token' . $suffix, $tokens['access'], 0);
		if ($tokens['remember_me'] !== null)
		{
			$this->SetCookie('grocy_session_remember_me_token' . $suffix, $tokens['remember_me'], time() + SessionService::SESSION_TOKEN_EXPIRATION_SECONDS_REMEMBER_ME);
		}

		return Profiles::Get($id);
	}

	private function SetCookie(string $name, string $value, int $expires): void
	{
		setcookie($name, $value, [
			'expires' => $expires,
			'httponly' => true,
			'samesite' => 'Lax',
			'path' => '/'
		]);
	}

	private function Columns(\PDO $pdo, string $table): array
	{
		return array_map(fn ($column) => $column['name'], $pdo->query('PRAGMA main.table_info(' . $table . ')')->fetchAll(\PDO::FETCH_ASSOC));
	}

	private function CopyMasterData(\PDO $pdo, string $sourceDb): void
	{
		$attach = $pdo->prepare('ATTACH DATABASE ? AS src');
		$attach->execute([$sourceDb]);

		try
		{
			$pdo->beginTransaction();

			// A fresh database comes with a few defaults ("Piece", "Fridge",
			// ...) - they make way for the real ones, ids included, so all the
			// references between the tables stay intact
			foreach (array_reverse(self::MASTER_DATA_TABLES) as $table)
			{
				$pdo->exec('DELETE FROM main.' . $table);
			}

			foreach (self::MASTER_DATA_TABLES as $table)
			{
				if ($table === 'quantity_unit_conversions')
				{
					$this->CopyConversions($pdo);
					continue;
				}

				$columns = implode(', ', array_map(fn ($column) => '"' . $column . '"', $this->Columns($pdo, $table)));

				// A product can have a parent product, which has to be there first
				$order = $table === 'products' ? ' ORDER BY parent_product_id IS NOT NULL, id' : ' ORDER BY id';
				$pdo->exec('INSERT INTO main.' . $table . ' (' . $columns . ') SELECT ' . $columns . ' FROM src.' . $table . $order);
			}

			$pdo->commit();
		}
		catch (\Throwable $ex)
		{
			if ($pdo->inTransaction())
			{
				$pdo->rollBack();
			}

			$pdo->exec('DETACH DATABASE src');
			throw $ex;
		}

		$pdo->exec('DETACH DATABASE src');
	}

	// Grocy keeps conversions in pairs: adding one creates its inverse, and
	// adding a conversion that exists is refused. So they are copied one by
	// one, skipping whatever a trigger already created - products added their
	// default conversions, and every copied one brought its inverse along.
	private function CopyConversions(\PDO $pdo): void
	{
		$columns = array_values(array_filter($this->Columns($pdo, 'quantity_unit_conversions'), fn ($column) => $column !== 'id'));
		$list = implode(', ', array_map(fn ($column) => '"' . $column . '"', $columns));

		// PDO binds every value as text - and inside IFNULL() SQLite does not
		// convert, 5 and '5' would not be equal
		$exists = $pdo->prepare('SELECT COUNT(*) FROM main.quantity_unit_conversions WHERE from_qu_id = ? AND to_qu_id = ? AND IFNULL(product_id, 0) = IFNULL(CAST(? AS INTEGER), 0)');
		$insert = $pdo->prepare('INSERT INTO main.quantity_unit_conversions (' . $list . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')');

		$pdo->exec('DELETE FROM main.quantity_unit_conversions');

		foreach ($pdo->query('SELECT ' . $list . ' FROM src.quantity_unit_conversions ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC) as $row)
		{
			$exists->execute([$row['from_qu_id'], $row['to_qu_id'], $row['product_id']]);

			$found = (int)$exists->fetchColumn();
			$exists->closeCursor();

			if ($found === 0)
			{
				$insert->execute(array_values($row));
			}
		}
	}

	// The default "admin" user of a fresh database becomes the person who
	// creates the profile - same login, administrator there as well
	private function SetUpCreator(\PDO $pdo, $creator): int
	{
		$userId = (int)$pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();

		if ($userId === 0)
		{
			$pdo->prepare('INSERT INTO users (username, password) VALUES (?, ?)')->execute([$creator->username, $creator->password]);
			$userId = (int)$pdo->lastInsertId();
		}
		else
		{
			$pdo->prepare('UPDATE users SET username = ?, first_name = ?, last_name = ?, password = ? WHERE id = ?')
				->execute([$creator->username, $creator->first_name, $creator->last_name, $creator->password, $userId]);
		}

		$adminPermissionId = $pdo->query("SELECT id FROM permission_hierarchy WHERE name = 'ADMIN'")->fetchColumn();
		$hasAdmin = $pdo->prepare('SELECT COUNT(*) FROM user_permissions WHERE user_id = ? AND permission_id = ?');
		$hasAdmin->execute([$userId, $adminPermissionId]);

		if ((int)$hasAdmin->fetchColumn() === 0)
		{
			$pdo->prepare('INSERT INTO user_permissions (user_id, permission_id) VALUES (?, ?)')->execute([$userId, $adminPermissionId]);
		}

		return $userId;
	}

	private function CreateLogin(\PDO $pdo, int $userId, bool $withRememberMe): array
	{
		$tokens = ['access' => null, 'remember_me' => null];
		$types = [
			'access' => [SessionService::SESSION_TOKEN_TYPE_ACCESS, SessionService::SESSION_TOKEN_EXPIRATION_SECONDS_ACCESS]
		];

		if ($withRememberMe)
		{
			$types['remember_me'] = [SessionService::SESSION_TOKEN_TYPE_REMEMBER_ME, SessionService::SESSION_TOKEN_EXPIRATION_SECONDS_REMEMBER_ME];
		}

		$insert = $pdo->prepare('INSERT INTO sessions (user_id, token_type, token_hash, expires, client_info, last_used) VALUES (?, ?, ?, ?, ?, ?)');

		foreach ($types as $key => [$type, $lifetime])
		{
			$token = bin2hex(random_bytes(32));
			$insert->execute([$userId, $type, hash('sha256', $token), date('Y-m-d H:i:s', time() + $lifetime), GetClientUserAgent(), date('Y-m-d H:i:s')]);
			$tokens[$key] = $token;
		}

		return $tokens;
	}

	public static function StoragePathFor(string $dataPath): string
	{
		$path = $dataPath . '/storage';

		if (GROCY_MODE === 'demo' || GROCY_MODE === 'prerelease')
		{
			$path .= '/' . (defined('GROCY_DEMO_DB_SUFFIX') ? GROCY_DEMO_DB_SUFFIX : GROCY_DEFAULT_LOCALE);
		}

		return $path;
	}

	private function CopyProductPictures(string $dataPath): void
	{
		$source = self::StoragePathFor(GROCY_PROFILE_DATAPATH) . '/productpictures';
		$target = self::StoragePathFor($dataPath) . '/productpictures';

		if (!is_dir($source))
		{
			return;
		}

		@mkdir($target, 0777, true);

		foreach ($this->DB->products()->where('picture_file_name IS NOT NULL') as $product)
		{
			$file = basename((string)$product->picture_file_name);

			if ($file !== '' && is_file($source . '/' . $file))
			{
				@copy($source . '/' . $file, $target . '/' . $file);
			}
		}
	}

	public function UpdateProfile(string $id, string $name, ?string $color): void
	{
		$this->AssertCanManage();

		if (Profiles::Get($id) === null)
		{
			throw new \Exception('Profile does not exist');
		}

		$name = $this->ValidName($name, $id);
		$color = self::ValidColor($color);
		$registry = Profiles::RawRegistry();

		if ($id === Profiles::MAIN)
		{
			$registry['main'] = ['name' => $name, 'color' => $color];
		}
		else
		{
			foreach ($registry['profiles'] as &$profile)
			{
				if (($profile['id'] ?? null) === $id)
				{
					$profile['name'] = $name;
					$profile['color'] = $color;
				}
			}
		}

		Profiles::SaveRegistry($registry);
	}

	/**
	 * Takes a profile out of the list. Its folder is not deleted but moved to
	 * data/profiles-deleted/, so a mistake can still be undone by hand.
	 */
	public function DeleteProfile(string $id): void
	{
		$this->AssertCanManage();

		if ($id === Profiles::MAIN || Profiles::Get($id) === null)
		{
			throw new \Exception($this->T('The main profile cannot be deleted'));
		}

		$archive = GROCY_DATAPATH . '/profiles-deleted';
		@mkdir($archive, 0777, true);

		if (!@rename(Profiles::DataPath($id), $archive . '/' . $id . '-' . date('Y-m-d-His')))
		{
			throw new \Exception($this->T('The folder of the profile could not be moved'));
		}

		$registry = Profiles::RawRegistry();
		$registry['profiles'] = array_values(array_filter($registry['profiles'], fn ($profile) => ($profile['id'] ?? null) !== $id));
		Profiles::SaveRegistry($registry);
	}

	// Only ever called on a folder this request has just created
	private function RemoveFolder(string $path): void
	{
		if (!is_dir($path) || strpos(realpath($path), realpath(GROCY_DATAPATH . '/profiles')) !== 0)
		{
			return;
		}

		$items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
		foreach ($items as $item)
		{
			$item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
		}

		@rmdir($path);
	}
}
