<?php

namespace Grocy\Controllers\Api;

use Grocy\Services\ProfilePermissionException;
use Grocy\Services\ProfileService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Profiles (Grocy Modern) - see Grocy\Helpers\Profiles. Only administrators
 * of the main profile may change anything, ProfileService checks that.
 */
class ProfilesApiController extends BaseApiController
{
	private function Run(Response $response, callable $work)
	{
		try
		{
			$result = $work();
			return $result === null ? $this->EmptyApiResponse($response) : $this->ApiResponse($response, $result);
		}
		catch (ProfilePermissionException $ex)
		{
			return $this->GenericErrorResponse($response, $ex->getMessage(), 403);
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

	public function GetProfiles(Request $request, Response $response, array $args)
	{
		return $this->Run($response, fn () => ProfileService::GetInstance()->GetProfiles());
	}

	public function CreateProfile(Request $request, Response $response, array $args)
	{
		return $this->Run($response, function () use ($request)
		{
			$body = $this->Body($request);
			return ProfileService::GetInstance()->CreateProfile((string)($body['name'] ?? ''), $body['color'] ?? null, filter_var($body['copy_master_data'] ?? false, FILTER_VALIDATE_BOOLEAN));
		});
	}

	public function UpdateProfile(Request $request, Response $response, array $args)
	{
		return $this->Run($response, function () use ($request, $args)
		{
			$body = $this->Body($request);
			ProfileService::GetInstance()->UpdateProfile((string)$args['profileId'], (string)($body['name'] ?? ''), $body['color'] ?? null);
			return null;
		});
	}

	public function DeleteProfile(Request $request, Response $response, array $args)
	{
		return $this->Run($response, function () use ($args)
		{
			ProfileService::GetInstance()->DeleteProfile((string)$args['profileId']);
			return null;
		});
	}
}
