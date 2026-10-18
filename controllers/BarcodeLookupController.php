<?php

namespace Grocy\Controllers;

use Grocy\Controllers\Users\User;
use Grocy\Services\BarcodeLookupService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class BarcodeLookupController extends BaseController
{
	public function Settings(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_ADMIN);

		$settings = BarcodeLookupService::GetInstance()->GetSettingsForUi();

		return $this->RenderPage($response, 'barcodelookupsettings', [
			'settings' => $settings,
			'estimatedCost' => BarcodeLookupService::EstimatedCost($settings['usage']),
			'averageCost' => $settings['usage']['lookups'] > 0 ? BarcodeLookupService::TotalCost($settings['usage']) / $settings['usage']['lookups'] : null
		]);
	}
}
