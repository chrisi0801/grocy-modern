<?php

namespace Grocy\Controllers\Api;

use Grocy\Controllers\Users\User;
use Grocy\Services\BarcodeLookupPermissionException;
use Grocy\Services\BarcodeLookupService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpException;

/**
 * Barcode lookup (Grocy Modern) - see BarcodeLookupService. Looking up and
 * creating products needs the right to edit master data; the settings (the
 * shared API key) belong to the administrators of the main profile.
 */
class BarcodeLookupApiController extends BaseApiController
{
	private function Run(Response $response, callable $work)
	{
		try
		{
			$result = $work();
			return $result === null ? $this->EmptyApiResponse($response) : $this->ApiResponse($response, $result);
		}
		catch (BarcodeLookupPermissionException $ex)
		{
			return $this->GenericErrorResponse($response, $ex->getMessage(), 403);
		}
		catch (HttpException $ex)
		{
			throw $ex;
		}
		catch (\Exception $ex)
		{
			return $this->GenericErrorResponse($response, $ex->getMessage());
		}
	}

	private function Body(Request $request): array
	{
		$body = $this->GetParsedAndFilteredRequestBody($request);
		return is_array($body) ? $body : [];
	}

	public function Lookup(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_MASTER_DATA_EDIT);
		return $this->Run($response, fn () => BarcodeLookupService::GetInstance()->Lookup((string)$args['barcode']));
	}

	public function LookupWithAi(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_MASTER_DATA_EDIT);
		return $this->Run($response, fn () => BarcodeLookupService::GetInstance()->LookupWithAi((string)$args['barcode']));
	}

	public function CreateProduct(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_MASTER_DATA_EDIT);
		return $this->Run($response, fn () => ['created_object_id' => BarcodeLookupService::GetInstance()->CreateProduct($this->Body($request))]);
	}

	public function GetSettings(Request $request, Response $response, array $args)
	{
		return $this->Run($response, fn () => BarcodeLookupService::GetInstance()->GetSettingsForUi());
	}

	public function UpdateSettings(Request $request, Response $response, array $args)
	{
		return $this->Run($response, function () use ($request)
		{
			BarcodeLookupService::GetInstance()->UpdateSettings($this->Body($request));
			return null;
		});
	}

	public function TestApiKey(Request $request, Response $response, array $args)
	{
		return $this->Run($response, fn () => BarcodeLookupService::GetInstance()->TestApiKey());
	}
}
