@extends('layout.default')

@section('title', $__t('Login'))

@section('content')
<div class="row">
	<div class="col-lg-4 offset-lg-4 col-md-6 offset-md-3 col-12">
		<h2 class="text-center">@yield('title')</h2>

		{{-- Profiles (Grocy Modern): every household has its own users, so the
		     login is for one of them - pick which --}}
		@if(\Grocy\Helpers\Profiles::IsInUse())
		<div class="login-profiles"
			role="tablist"
			aria-label="{{ $__t('Profile') }}">
			@foreach(\Grocy\Helpers\Profiles::All() as $profile)
			<a class="btn btn-sm @if($profile['id'] === GROCY_PROFILE_ID) btn-primary @else btn-outline-dark @endif"
				role="tab"
				aria-selected="{{ $profile['id'] === GROCY_PROFILE_ID ? 'true' : 'false' }}"
				href="#"
				data-profile-switch="{{ $profile['id'] }}"
				style="--g-profile-color: {{ $profile['color'] }}">
				<span class="profile-dot"></span>
				{{ $profile['name'] ?: $__t('Main profile') }}
			</a>
			@endforeach
		</div>
		@endif

		<hr class="my-2">

		<form method="post"
			action="{{ $U('/login') }}"
			id="login-form"
			novalidate>

			<div class="form-group">
				<label for="username">{{ $__t('Username') }}</label>
				<input type="text"
					class="form-control"
					required
					id="username"
					name="username">
			</div>


			<input type="hidden"
				id="password_base64"
				name="password_base64">
			<div class="form-group">
				<label for="password_input">{{ $__t('Password') }}</label>
				<input type="password"
					class="form-control"
					required
					id="password_input">
				<div id="login-error"
					class="form-text text-danger d-none"></div>
			</div>

			<div class="form-group mt-n2">
				<div class="custom-control custom-checkbox">
					<input type="checkbox"
						class="form-check-input custom-control-input"
						id="remember_me"
						name="remember_me">
					<label class="form-check-label custom-control-label"
						for="remember_me">
						{{ $__t('Remember Me') }}
					</label>
				</div>
			</div>

			<button id="login-button"
				class="btn btn-success">{{ $__t('OK') }}</button>

		</form>
	</div>
</div>
@stop
