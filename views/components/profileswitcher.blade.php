{{-- Profiles (Grocy Modern): which household this is, and the way to the others --}}
@php
$currentProfile = \Grocy\Helpers\Profiles::Get(GROCY_PROFILE_ID);
$profileName = fn ($profile) => $profile['name'] ?: $__t('Main profile');
// Only the profiles this browser is logged in to - someone who lives in one
// building only never has the other one in their way. Without logins of
// Grocy's own (authentication disabled, demo mode, a reverse proxy doing it)
// there is nothing to go by, so then all of them.
$withOwnLogin = !GROCY_DISABLE_AUTH && GROCY_MODE === 'production' && stripos(GROCY_AUTH_CLASS, 'ReverseProxy') === false;
$switchableProfiles = array_values(array_filter(\Grocy\Helpers\Profiles::All(), function ($profile) use ($withOwnLogin)
{
	$suffix = \Grocy\Helpers\Profiles::CookieSuffix($profile['id']);
	return !$withOwnLogin
		|| $profile['id'] === GROCY_PROFILE_ID
		|| isset($_COOKIE['grocy_session_access_token' . $suffix])
		|| isset($_COOKIE['grocy_session_remember_me_token' . $suffix]);
}));
@endphp
<div class="dropdown profile-switcher">
	<button class="profile-chip"
		type="button"
		data-toggle="dropdown"
		aria-haspopup="true"
		aria-expanded="false"
		style="--g-profile-color: {{ GROCY_PROFILE_COLOR }}"
		title="{{ $__t('Switch profile') }}">
		<span class="profile-dot"></span>
		<span class="profile-chip-name">{{ $profileName($currentProfile) }}</span>
		<i class="fa-solid fa-chevron-down"></i>
	</button>
	<div class="dropdown-menu">
		<h6 class="dropdown-header">{{ $__t('Switch profile') }}</h6>
		@foreach($switchableProfiles as $profile)
		<a class="dropdown-item profile-switch-item @if($profile['id'] === GROCY_PROFILE_ID) active @endif"
			href="#"
			data-profile-switch="{{ $profile['id'] }}"
			style="--g-profile-color: {{ $profile['color'] }}">
			<span class="profile-dot"></span>
			<span class="flex-grow-1">{{ $profileName($profile) }}</span>
			@if($profile['id'] === GROCY_PROFILE_ID)
			<i class="fa-solid fa-check"></i>
			@endif
		</a>
		@endforeach
		<div class="dropdown-divider"></div>
		@if($withOwnLogin)
		<a class="dropdown-item"
			href="#"
			data-profile-login>
			<i class="fa-solid fa-right-to-bracket"></i>&nbsp;{{ $__t('Log in to another profile') }}
		</a>
		@endif
		@if(GROCY_PROFILE_ID === \Grocy\Helpers\Profiles::MAIN)
		<a class="dropdown-item permission-ADMIN"
			href="{{ $U('/profiles') }}">
			<i class="fa-solid fa-building"></i>&nbsp;{{ $__t('Manage profiles') }}
		</a>
		@endif
	</div>
</div>
