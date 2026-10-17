<?php

namespace Grocy\Controllers;

use Grocy\Controllers\Users\User;
use Grocy\Helpers\Profiles;
use Grocy\Services\ProfileService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ProfilesController extends BaseController
{
	public function Overview(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_ADMIN);

		return $this->RenderPage($response, 'profiles', [
			'profiles' => ProfileService::GetInstance()->GetProfiles(),
			'canManage' => ProfileService::GetInstance()->CanManage(),
			'colors' => Profiles::COLORS
		]);
	}
}
