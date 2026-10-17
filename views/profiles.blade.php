@extends('layout.default')

@section('title', $__t('Profiles'))

@section('content')
<div class="row">
	<div class="col">
		<div class="title-related-links">
			<h2 class="title">@yield('title')</h2>
			@if($canManage)
			<div class="related-links d-flex order-2">
				<button id="profile-new-button"
					class="btn btn-primary responsive-button m-1 mt-md-0 mb-md-0"
					type="button">
					<i class="fa-solid fa-plus"></i>&nbsp;{{ $__t('New profile') }}
				</button>
			</div>
			@endif
		</div>
	</div>
</div>

<hr class="my-2">

<div class="row">
	<div class="col-lg-8 col-12">
		<p class="text-muted">{{ $__t('Every profile is a household of its own - for example one per building. Stock, chores, tasks, calendar, shopping lists, budget and users are completely separate; the switcher next to the logo changes between them.') }}</p>

		@if(!$canManage)
		<div class="alert alert-info">{{ $__t('Profiles can only be managed by an administrator of the main profile') }}</div>
		@endif

		<script>
			var ProfilesPage = {
				Colors: @json($colors),
				Profiles: @json($profiles),
				MainProfileName: @json($__t('Main profile'))
			};
		</script>

		<div class="profile-list">
			@foreach($profiles as $profile)
			<div class="profile-row"
				style="--g-profile-color: {{ $profile['color'] }}">
				<span class="profile-dot"></span>
				<div class="profile-row-main">
					<div class="profile-row-name">
						{{ $profile['name'] ?: $__t('Main profile') }}
						@if($profile['is_main'] && $profile['name'])
						<span class="badge badge-secondary">{{ $__t('Main profile') }}</span>
						@endif
						@if($profile['is_current'])
						<span class="badge badge-success">{{ $__t('Current') }}</span>
						@endif
					</div>
					<div class="profile-row-sub">
						@if($profile['is_main'])
						{{ $__t('The data that was there before profiles existed') }}
						@else
						{{ $__t('Created %s', substr((string)$profile['created'], 0, 10)) }}
						@endif
					</div>
				</div>
				<div class="profile-row-actions">
					@if(!$profile['is_current'])
					<button class="btn btn-sm btn-outline-dark"
						type="button"
						data-profile-switch="{{ $profile['id'] }}">
						{{ $__t('Switch') }}
					</button>
					@endif
					@if($canManage)
					<button class="btn btn-sm btn-info profile-edit-button"
						type="button"
						data-profile-id="{{ $profile['id'] }}"
						title="{{ $__t('Edit this item') }}">
						<i class="fa-solid fa-edit"></i>
					</button>
					@if(!$profile['is_main'])
					<button class="btn btn-sm btn-danger profile-delete-button"
						type="button"
						data-profile-id="{{ $profile['id'] }}"
						title="{{ $__t('Delete this item') }}">
						<i class="fa-solid fa-trash"></i>
					</button>
					@endif
					@endif
				</div>
			</div>
			@endforeach
		</div>

		@if(count($profiles) === 1 && $canManage)
		<p class="text-muted small mt-3">{{ $__t('As long as there is only this one profile, nothing changes - the switcher only appears once there is a second one.') }}</p>
		@endif
	</div>
</div>

{{-- The form for "new profile" and "edit", shown in a dialog --}}
<template id="profile-form-template">
	<form class="profile-form"
		novalidate>
		<div class="form-group">
			<label for="profile-name">{{ $__t('Name') }}</label>
			<input type="text"
				class="form-control"
				id="profile-name"
				maxlength="40"
				required
				placeholder="{{ $__t('e.g. Main house, Holiday flat') }}">
			<div class="invalid-feedback">{{ $__t('A name is required') }}</div>
		</div>
		<div class="form-group">
			<label>{{ $__t('Colour') }}</label>
			<div class="profile-colors">
				@foreach($colors as $index => $color)
				<input type="radio"
					name="profile-color"
					id="profile-color-{{ $index }}"
					value="{{ $color }}">
				<label for="profile-color-{{ $index }}"
					style="--g-profile-color: {{ $color }}"
					title="{{ $color }}"><span class="profile-dot"></span></label>
				@endforeach
			</div>
		</div>
		<div class="profile-create-only">
			<div class="form-group">
				<div class="custom-control custom-checkbox">
					<input class="custom-control-input"
						type="checkbox"
						id="profile-copy-master-data"
						checked>
					<label class="custom-control-label"
						for="profile-copy-master-data">{{ $__t('Take over the master data of this profile') }}</label>
				</div>
				<small class="form-text text-muted">{{ $__t('Products, quantity units, product groups, locations and stores are copied once - after that both profiles keep their own. Stock, chores, tasks, calendar and everything else start empty.') }}</small>
			</div>
			<p class="small text-muted mb-0">
				<i class="fa-solid fa-user-shield"></i>
				{{ $__t('You become the administrator of the new profile, with your user name and password. Further users are added there, under "Manage users".') }}
			</p>
		</div>
	</form>
</template>
@stop
