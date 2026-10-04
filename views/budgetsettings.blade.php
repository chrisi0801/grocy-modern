@extends('layout.default')

@section('title', $__t('Budget settings'))

@section('content')
@include('components.budgetassets')
@include('components.budgetheader', ['active' => 'settings', 'budgetId' => $selectedBudget['id']])

@php
$expenseCategories = array_values(array_filter($categories, fn ($c) => !$c['is_income']));
$incomeCategories = array_values(array_filter($categories, fn ($c) => $c['is_income']));
$canEdit = $selectedBudget['can_edit'];
@endphp

<div class="budget-section-title">
	<span>{{ $__t('Budgets') }}</span>
	<a class="btn btn-sm btn-outline-primary show-as-dialog-link"
		href="{{ $U('/budget/budget/new?embedded') }}">
		<i class="fa-solid fa-plus"></i>&nbsp;{{ $__t('New budget') }}
	</a>
</div>
<div class="budget-rows">
	@foreach($budgets as $budget)
	<div class="budget-row">
		<div class="budget-row-main">
			<div class="budget-category-name">
				@if(empty($budget['owner_user_id']))
				<i class="fa-solid fa-house text-muted"></i>
				@else
				<i class="fa-solid fa-user text-muted"></i>
				@endif
				{{ $budget['name'] }}
			</div>
			<div class="budget-row-sub">
				@if(empty($budget['owner_user_id']))
				{{ $__t('Shared - everyone with access to the budget can book here') }}
				@else
				{{ $__t('Personal budget of %s', $budget['owner_name'] ?? '?') }}
				@endif
			</div>
		</div>
		<div class="budget-row-actions">
			<a class="btn btn-sm btn-info show-as-dialog-link @if(!$budget['can_edit']) disabled @endif"
				href="{{ $U('/budget/budget/' . $budget['id'] . '?embedded') }}"
				title="{{ $__t('Edit this item') }}">
				<i class="fa-solid fa-edit"></i>
			</a>
			@if($isAdmin)
			<a class="btn btn-sm btn-danger budget-delete-button"
				href="#"
				data-budget-id="{{ $budget['id'] }}"
				data-budget-name="{{ $budget['name'] }}"
				title="{{ $__t('Delete this item') }}">
				<i class="fa-solid fa-trash"></i>
			</a>
			@endif
		</div>
	</div>
	@endforeach
</div>

<div class="budget-section-title mt-4">
	<span>{{ $__t('Categories') }}</span>
</div>

@include('components.budgetswitcher', ['switcherUrl' => '/budget/settings'])

@if(!$canEdit)
<div class="budget-readonly-note">
	<i class="fa-solid fa-eye mt-1"></i>
	<div>{{ $__t('This is the personal budget of %s - you can look at it, but only they can change it.', $selectedBudget['owner_name'] ?? '?') }}</div>
</div>
@endif

@foreach([[$__t('Expenses'), $expenseCategories, 'expense'], [$__t('Income'), $incomeCategories, 'income']] as [$title, $list, $kind])
<div class="budget-section-title">
	<span>{{ $title }}</span>
	@if($canEdit)
	<a class="btn btn-sm btn-outline-primary show-as-dialog-link"
		href="{{ $U('/budget/category/new?embedded&budget=' . $selectedBudget['id'] . '&type=' . $kind) }}">
		<i class="fa-solid fa-plus"></i>&nbsp;{{ $__t('New category') }}
	</a>
	@endif
</div>
<div class="budget-rows">
	@forelse($list as $category)
	<div class="budget-row">
		<div class="budget-row-main">
			<div class="budget-category-name">
				{{ $category['name'] }}
				@if($category['carryover'])
				<span class="budget-badge">{{ $__t('Carryover') }}</span>
				@endif
			</div>
		</div>
		@if($canEdit)
		<div class="budget-row-actions">
			<a class="btn btn-sm btn-info show-as-dialog-link"
				href="{{ $U('/budget/category/' . $category['id'] . '?embedded') }}"
				title="{{ $__t('Edit this item') }}">
				<i class="fa-solid fa-edit"></i>
			</a>
			<a class="btn btn-sm btn-danger budget-category-delete-button"
				href="#"
				data-category-id="{{ $category['id'] }}"
				data-category-name="{{ $category['name'] }}"
				title="{{ $__t('Delete this item') }}">
				<i class="fa-solid fa-trash"></i>
			</a>
		</div>
		@endif
	</div>
	@empty
	<div class="budget-empty">{{ $__t('No categories yet') }}</div>
	@endforelse
</div>
@endforeach
@stop
