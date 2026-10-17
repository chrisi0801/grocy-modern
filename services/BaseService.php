<?php

namespace Grocy\Services;

class BaseService
{
	public function __construct()
	{
		$this->DB = DatabaseService::GetInstance()->GetDbConnection();
	}

	private static $Instances = [];
	protected $DB;

	// Grocy Modern: while a new profile's database is set up, services must
	// be created afresh - an existing one still works on the database of the
	// current request. Returns what was there, to put it back afterwards.
	public static function SwapInstances(array $instances = []): array
	{
		$previous = self::$Instances;
		self::$Instances = $instances;
		return $previous;
	}

	public static function GetInstance()
	{
		$className = get_called_class();
		if (!isset(self::$Instances[$className]))
		{
			self::$Instances[$className] = new $className();
		}

		return self::$Instances[$className];
	}
}
