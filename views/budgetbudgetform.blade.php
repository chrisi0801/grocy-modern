@extends('layout.default')

@if($mode == 'edit')
@section('title', $__t('Edit budget'))
@else
@section('title', $__t('New budget'))
@endif

@section('content')
@include('components.budgetassets')

@php
$owner = $budget !== null && $budget['owner_user_id'] !== null ? (int)$budget['owner_user_id'] : null;
// The owner is an administrator's call - everybody else can set up a shared
// budget or one of their own, and cannot move a budget to somebody else
$ownerLocked = $mode == 'edit' && !$isAdmin;
@endphp

<div class="row">
	<div class="col">
		<h2 class="title">@yield('title')</h2>
	</div>
</div>

<hr class="my-2">

<div class="row">
	<div class="col-lg-6 col-12">

		<script>
			var BudgetForm = {
				Mode: '{{ $mode }}',
				BudgetId: {{ $budget !== null ? $budget['id'] : 'null' }}
			};
		</script>

		@if(!$canEdit)
		<div class="budget-readonly-note">
			<i class="fa-solid fa-eye mt-1"></i>
			<div>{{ $__t('This is the personal budget of %s - you can look at it, but only they can change it.', $budget['owner_name'] ?? '?') }}</div>
		</div>
		@endif

		<form id="budget-budget-form"
			novalidate>
			<fieldset @if(!$canEdit) disabled @endif>

				<div class="form-group">
					<label for="name">{{ $__t('Name') }}</label>
					<input type="text"
						class="form-control"
						required
						id="name"
						value="{{ $budget['name'] ?? '' }}"
						placeholder="{{ $__t('e.g. Household, Anna, Holiday') }}">
					<div class="invalid-feedback">{{ $__t('A name is required') }}</div>
				</div>

				<div class="form-group">
					<label for="owner_user_id">{{ $__t('Belongs to') }}</label>
					<select class="custom-control custom-select"
						id="owner_user_id"
						@if($ownerLocked) disabled @endif>
						<option value=""
							@if($owner === null) selected @endif>{{ $__t('Everyone (shared)') }}</option>
						@foreach($users as $user)
						@if($isAdmin || (int)$user->id === $currentUserId || (int)$user->id === $owner)
						<option value="{{ $user->id }}"
							@if($owner === (int)$user->id) selected @endif>{{ $__t('Personal budget of %s', $user->display_name) }}</option>
						@endif
						@endforeach
					</select>
					<small class="form-text text-muted">
						{{ $__t('Everyone with access to the budget can look at every budget. A personal budget can only be changed by the person it belongs to.') }}
						@if($ownerLocked)
						{{ $__t('Only an administrator can change the owner of a budget') }}.
						@endif
					</small>
				</div>

				@if($mode == 'create')
				<p class="small text-muted">{{ $__t('A new budget starts with the usual categories - rename or delete them in the settings as you like.') }}</p>
				@endif

				<button id="save-budget-budget-button"
					class="btn btn-success">{{ $__t('Save') }}</button>

			</fieldset>
		</form>
	</div>
</div>
@stop
