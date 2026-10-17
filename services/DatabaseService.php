<?php

namespace Grocy\Services;

use Grocy\Services\UsersService;
use LessQL\Database;

class DatabaseService
{
	private static $DbConnection = null;
	private static $DbConnectionRaw = null;
	private static $instance = null;

	public function ExecuteDbQuery(string $sql)
	{
		$pdo = $this->GetDbConnectionRaw();

		if ($this->ExecuteDbStatement($sql) === true)
		{
			return $pdo->query($sql);
		}

		return false;
	}

	public function ExecuteDbStatement(string $sql, ?array $params = null)
	{
		$pdo = $this->GetDbConnectionRaw();

		if (GROCY_MODE === 'dev')
		{
			$logFilePath = GROCY_DATAPATH . '/sql.log';
			if (file_exists($logFilePath))
			{
				file_put_contents($logFilePath, $sql . PHP_EOL, FILE_APPEND);
			}
		}

		if ($params === null)
		{
			if ($pdo->exec($sql) === false)
			{
				throw new \Exception(implode(' | ', $pdo->errorInfo()));
			}
		}
		else
		{
			$cmd = $pdo->prepare($sql);

			if ($cmd === false)
			{
				throw new \Exception(implode(' | ', $pdo->errorInfo()));
			}

			if ($cmd->execute($params) === false)
			{
				throw new \Exception(implode(' | ', $cmd->errorInfo()));
			}
		}

		return true;
	}

	public function GetDbChangedTime()
	{
		return date('Y-m-d H:i:s', filemtime($this->GetDbFilePath()));
	}

	public function GetDbConnection()
	{
		if (self::$DbConnection == null)
		{
			self::$DbConnection = new Database($this->GetDbConnectionRaw());
		}

		if (GROCY_MODE === 'dev')
		{
			$logFilePath = GROCY_DATAPATH . '/sql.log';
			if (file_exists($logFilePath))
			{
				self::$DbConnection->setQueryCallback(function ($query, $params) use ($logFilePath)
				{
					file_put_contents($logFilePath, $query . ' #### ' . implode(';', $params) . PHP_EOL, FILE_APPEND);
				});
			}
		}

		return self::$DbConnection;
	}

	public function GetDbConnectionRaw()
	{
		if (self::$DbConnectionRaw == null)
		{
			$pdo = new \PDO\Sqlite('sqlite:' . $this->GetDbFilePath());
			$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
			$pdo->setAttribute(\PDO::ATTR_ORACLE_NULLS, \PDO::NULL_EMPTY_STRING);

			$pdo->createFunction('regexp', function ($pattern, $value)
			{
				mb_regex_encoding('UTF-8');
				return (false !== mb_ereg($pattern, $value)) ? 1 : 0;
			});

			$pdo->createFunction('grocy_user_setting', function ($value)
			{
				$usersService = new UsersService();
				return $usersService->GetUserSetting(GROCY_USER_ID, $value);
			});


			// Unfortunately not included by default
			// https://www.sqlite.org/lang_mathfunc.html#ceil
			$pdo->createFunction('ceil', function ($value)
			{
				return ceil($value);
			});

			self::$DbConnectionRaw = $pdo;
		}

		return self::$DbConnectionRaw;
	}

	public function SetDbChangedTime($dateTime)
	{
		touch($this->GetDbFilePath(), strtotime($dateTime));
	}

	public static function GetInstance()
	{
		if (self::$instance == null)
		{
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function GetDbFilePath()
	{
		if (self::$DbFilePathOverride !== null)
		{
			return self::$DbFilePathOverride;
		}

		// Grocy Modern: the database of the profile this request is for
		return self::DbFilePathFor(defined('GROCY_PROFILE_DATAPATH') ? GROCY_PROFILE_DATAPATH : GROCY_DATAPATH);
	}

	public static function DbFilePathFor(string $dataPath)
	{
		if (GROCY_MODE === 'demo' || GROCY_MODE === 'prerelease')
		{
			$dbSuffix = GROCY_DEFAULT_LOCALE;
			if (defined('GROCY_DEMO_DB_SUFFIX'))
			{
				$dbSuffix = GROCY_DEMO_DB_SUFFIX;
			}

			return $dataPath . '/grocy_' . $dbSuffix . '.db';
		}

		return $dataPath . '/grocy.db';
	}

	// Grocy Modern: setting up a new profile means migrating its database -
	// for that one moment the connection points there instead. Everything
	// that already holds a connection keeps the one it has.
	private static $DbFilePathOverride = null;

	public static function UseDatabaseFile(?string $path)
	{
		self::$DbFilePathOverride = $path;
		self::$DbConnection = null;
		self::$DbConnectionRaw = null;
	}
}
