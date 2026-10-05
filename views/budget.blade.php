@extends('layout.default')

@section('title', $__t('Budget'))

@section('content')
@include('components.budgetassets')
@include('components.budgetheader', ['active' => 'overview', 'budgetId' => $selectedBudget['id']])

@php
$totals = $overview['totals'];
$canEdit = $overview['budget']['can_edit'];
$expenses = array_values(array_filter($overview['categories'], fn ($c) => !$c['is_income']));
$income = array_values(array_filter($overview['categories'], fn ($c) => $c['is_income']));
$monthQuery = '&month=' . $month;
@endphp

{{-- The page scripts load at the end of the body, so plain values here --}}
<script>
	var BudgetPage = {
		BudgetId: {{ $selectedBudget['id'] }},
		Month: '{{ $month }}',
		MonthLabel: @json($monthLabel($month))
	};
</script>

@include('components.budgetswitcher', ['switcherUrl' => '/budget', 'switcherQuery' => $monthQuery])
@include('components.budgetmonthnav', ['monthUrl' => '/budget'])

@if(!$canEdit)
<div class="budget-readonly-note">
	<i class="fa-solid fa-eye mt-1"></i>
	<div>{{ $__t('This is the personal budget of %s - you can look at it, but only they can change it.', $selectedBudget['owner_name'] ?? '?') }}</div>
</div>
@endif

@if($canEdit)
<div class="d-flex flex-wrap mb-3"
	style="gap: 0.5rem;">
	<a class="btn btn-primary show-as-dialog-link"
		href="{{ $U('/budget/transaction/new?embedded&budget=' . $selectedBudget['id'] . '&type=expense') }}">
		<i class="fa-solid fa-minus"></i>&nbsp;{{ $__t('Expense') }}
	</a>
	<a class="btn btn-outline-dark show-as-dialog-link"
		href="{{ $U('/budget/transaction/new?embedded&budget=' . $selectedBudget['id'] . '&type=income') }}">
		<i class="fa-solid fa-plus"></i>&nbsp;{{ $__t('Deposit') }}
	</a>
	@if(count($budgets) > 1)
	<a class="btn btn-outline-dark show-as-dialog-link"
		href="{{ $U('/budget/transfer/new?embedded&budget=' . $selectedBudget['id']) }}">
		<i class="fa-solid fa-right-left"></i>&nbsp;{{ $__t('Budget transfer') }}
	</a>
	@endif
</div>
@endif

<div class="budget-tiles">
	{{-- The explanation lives behind the (i) - the tile only carries the number --}}
	<div class="budget-tile budget-tile-primary">
		<div class="budget-tile-label">{{ $isPastMonth ? $__t('Balance of the month') : $__t('Available') }}</div>
		<div class="budget-tile-value">{{ $money($isPastMonth ? $totals['saldo'] : $totals['available']) }}</div>
		<button type="button"
			class="budget-tile-info"
			data-info-template="#budget-primary-info"
			aria-label="{{ $__t('How is this calculated?') }}"
			title="{{ $__t('How is this calculated?') }}">
			<i class="fa-solid fa-circle-info"></i>
		</button>
	</div>
	<div class="budget-tile">
		<div class="budget-tile-label">{{ $__t('Balance') }}</div>
		<div class="budget-tile-value @if($totals['balance'] < 0) budget-negative @endif">{{ $money($totals['balance']) }}</div>
		<div class="budget-tile-hint">{{ $isCurrentMonth ? $__t('Today') : $__t('End of month') }}</div>
	</div>
	<div class="budget-tile">
		<div class="budget-tile-label">{{ $__t('Income') }}</div>
		<div class="budget-tile-value budget-positive">{{ $money($totals['income']) }}</div>
	</div>
	<div class="budget-tile">
		<div class="budget-tile-label">{{ $__t('Expenses') }}</div>
		<div class="budget-tile-value">{{ $money($totals['expenses']) }}</div>
		@if($totals['planned'] > 0)
		<div class="budget-tile-hint">{{ $__t('of %s planned', $money($totals['planned'])) }}</div>
		@endif
	</div>
</div>

<div class="budget-section-title">
	<span>{{ $__t('Expenses') }}</span>
	@if($totals['planned'] > 0)
	<span>{{ $__t('%s left', $money($totals['remaining'])) }}</span>
	@endif
</div>

@php
$active = array_values(array_filter($expenses, fn ($c) => $c['plan'] !== 0 || $c['spent'] !== 0 || $c['carried_in'] !== 0));
$idle = array_values(array_filter($expenses, fn ($c) => !($c['plan'] !== 0 || $c['spent'] !== 0 || $c['carried_in'] !== 0)));
@endphp

@if(count($expenses) === 0)
<div class="budget-empty">
	{{ $__t('This budget has no expense categories yet.') }}
	<a href="{{ $U('/budget/settings?budget=' . $selectedBudget['id']) }}">{{ $__t('Set them up') }}</a>
</div>
@elseif(count($active) === 0)
<div class="budget-empty">
	@if($canEdit)
	{{ $__t('Nothing planned or spent this month yet. Tap a category below to set its monthly plan.') }}
	@else
	{{ $__t('Nothing planned or spent this month yet.') }}
	@endif
</div>
@endif

@if(count($active) > 0)
<div class="budget-categories">
	@foreach($active as $category)
	@php
	$available = $category['plan'] + $category['carried_in'];
	$ratio = $available > 0 ? $category['spent'] / $available : ($category['spent'] > 0 ? 1.5 : 0);
	$barClass = $ratio > 1 ? 'is-over' : ($ratio >= 0.9 ? 'is-warning' : '');
	@endphp
	<div class="budget-category">
		<div class="budget-category-head">
			<a class="budget-category-name"
				href="{{ $U('/budget/transactions?budget=' . $selectedBudget['id'] . '&month=' . $month . '&category=' . $category['id']) }}">
				{{ $category['name'] }}
				@if($category['carryover'])
				<span class="budget-badge"
					title="{{ $__t('Whatever is left moves into the next month') }}">{{ $__t('Carryover') }}</span>
				@endif
			</a>
			<span class="budget-category-remaining @if($category['remaining'] < 0) budget-negative @endif">
				{{ $money($category['remaining']) }}
			</span>
		</div>
		<div class="budget-progress"
			role="progressbar"
			aria-valuemin="0"
			aria-valuemax="100"
			aria-valuenow="{{ (int)min(100, max(0, round($ratio * 100))) }}">
			<div class="budget-progress-bar {{ $barClass }}"
				style="width: {{ min(100, max(0, round($ratio * 100))) }}%"></div>
		</div>
		<div class="budget-category-meta">
			<span>
				{{ $__t('%1$s of %2$s spent', $money($category['spent']), $money($available)) }}
				@if($category['carried_in'] != 0)
				<span class="d-block">{{ $__t('incl. %s from last month', $money($category['carried_in'])) }}</span>
				@endif
			</span>
			<button type="button"
				class="budget-plan-button"
				data-category-id="{{ $category['id'] }}"
				data-category-name="{{ $category['name'] }}"
				data-plan="{{ $category['plan'] / 100 }}"
				@if(!$canEdit) disabled @endif>
				{{ $__t('Plan') }}: {{ $money($category['plan']) }}
			</button>
		</div>
	</div>
	@endforeach
</div>
@endif

@if(count($idle) > 0)
<div class="budget-section-title">
	<span>{{ $__t('Without plan') }}</span>
</div>
<div class="budget-rows">
	@foreach($idle as $category)
	<div class="budget-row">
		<a class="budget-category-name"
			href="{{ $U('/budget/transactions?budget=' . $selectedBudget['id'] . '&month=' . $month . '&category=' . $category['id']) }}">
			{{ $category['name'] }}
			@if($category['carryover'])
			<span class="budget-badge">{{ $__t('Carryover') }}</span>
			@endif
		</a>
		@if($canEdit)
		<button type="button"
			class="budget-plan-button"
			data-category-id="{{ $category['id'] }}"
			data-category-name="{{ $category['name'] }}"
			data-plan="0">
			<i class="fa-solid fa-plus"></i> {{ $__t('Set plan') }}
		</button>
		@endif
	</div>
	@endforeach
</div>
@endif

<div class="budget-section-title">
	<span>{{ $__t('Income') }}</span>
	<span>{{ $money($totals['income']) }}</span>
</div>
<div class="budget-rows">
	@forelse($income as $category)
	<a class="budget-row"
		href="{{ $U('/budget/transactions?budget=' . $selectedBudget['id'] . '&month=' . $month . '&category=' . $category['id']) }}">
		<span>{{ $category['name'] }}</span>
		<span class="budget-row-amount @if($category['received'] > 0) budget-positive @endif">{{ $money($category['received']) }}</span>
	</a>
	@empty
	<div class="budget-empty">{{ $__t('This budget has no income categories yet.') }}</div>
	@endforelse
</div>

<template id="budget-primary-info">
	@if($isPastMonth)
	<div class="budget-info-title">{{ $__t('Balance of the month') }}</div>
	<p>{{ $__t('What was left of this month: everything that came in, minus everything that went out.') }}</p>
	<table class="budget-info-sum">
		<tr>
			<td>{{ $__t('Income') }}</td>
			<td>{{ $money($totals['income']) }}</td>
		</tr>
		<tr>
			<td>{{ $__t('Expenses') }}</td>
			<td>{{ $money(-$totals['expenses']) }}</td>
		</tr>
		<tr class="budget-info-result">
			<td>{{ $__t('Balance of the month') }}</td>
			<td>{{ $money($totals['saldo']) }}</td>
		</tr>
	</table>
	@else
	<div class="budget-info-title">{{ $__t('Available') }}</div>
	<p>{{ $__t('The money that is really free: neither spent yet nor set aside by the plan for the rest of the month.') }}</p>
	<table class="budget-info-sum">
		<tr>
			<td>{{ $__t('Balance') }} <span class="text-muted">({{ $isCurrentMonth ? $__t('Today') : $__t('End of month') }})</span></td>
			<td>{{ $money($totals['balance']) }}</td>
		</tr>
		<tr>
			<td>{{ $__t('Still planned this month') }}</td>
			<td>{{ $money(-$totals['committed']) }}</td>
		</tr>
		<tr class="budget-info-result">
			<td>{{ $__t('Available') }}</td>
			<td>{{ $money($totals['available']) }}</td>
		</tr>
	</table>
	<p>{{ $__t('"Still planned" is what is left of the monthly plan of each category, including what was carried over. An overspent category counts as zero here.') }}</p>
	@if($totals['available'] < 0)
	<p class="budget-negative">{{ $__t('Below zero, more is planned than there is money - lower a plan or move money into this budget.') }}</p>
	@endif
	@endif
</template>

@if($overview['uncategorized']['income'] != 0 || $overview['uncategorized']['expenses'] != 0)
<div class="budget-section-title">
	<span>{{ $__t('Without category') }}</span>
</div>
<div class="budget-rows">
	@if($overview['uncategorized']['income'] != 0)
	<div class="budget-row">
		<span>{{ $__t('Income') }}</span>
		<span class="budget-row-amount budget-positive">{{ $money($overview['uncategorized']['income']) }}</span>
	</div>
	@endif
	@if($overview['uncategorized']['expenses'] != 0)
	<div class="budget-row">
		<span>{{ $__t('Expenses') }}</span>
		<span class="budget-row-amount">{{ $money($overview['uncategorized']['expenses']) }}</span>
	</div>
	@endif
</div>
@endif
@stop
