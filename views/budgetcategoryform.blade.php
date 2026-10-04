@extends('layout.default')

@if($mode == 'edit')
@section('title', $__t('Edit category'))
@else
@section('title', $__t('New category'))
@endif

@section('content')
@include('components.budgetassets')

@php
$isIncome = $category !== null ? (bool)$category['is_income'] : $initialType === 'income';
@endphp

<div class="row">
	<div class="col">
		<h2 class="title">@yield('title')</h2>
		<div class="text-muted">{{ $budget['name'] }}</div>
	</div>
</div>

<hr class="my-2">

<div class="row">
	<div class="col-lg-6 col-12">

		<script>
			var BudgetForm = {
				Mode: '{{ $mode }}',
				CategoryId: {{ $category !== null ? $category['id'] : 'null' }},
				BudgetId: {{ $budget['id'] }},
				SortOrder: {{ $category !== null ? (int)$category['sort_order'] : 'null' }},
				CurrentMonth: '{{ date('Y-m') }}'
			};
		</script>

		<form id="budget-category-form"
			novalidate>
			<fieldset @if(!$canEdit) disabled @endif>

				<div class="budget-type-switch"
					role="radiogroup"
					aria-label="{{ $__t('Type') }}">
					<input type="radio"
						name="type"
						id="type-expense"
						value="expense"
						@if(!$isIncome) checked @endif>
					<label for="type-expense">{{ $__t('Expenses') }}</label>
					<input type="radio"
						name="type"
						id="type-income"
						value="income"
						@if($isIncome) checked @endif>
					<label for="type-income">{{ $__t('Income') }}</label>
				</div>

				<div class="form-group">
					<label for="name">{{ $__t('Name') }}</label>
					<input type="text"
						class="form-control"
						required
						id="name"
						value="{{ $category['name'] ?? '' }}">
					<div class="invalid-feedback">{{ $__t('A name is required') }}</div>
				</div>

				<div class="budget-expense-only">
					<div class="form-group">
						<div class="custom-control custom-checkbox">
							<input class="form-check-input custom-control-input"
								type="checkbox"
								id="carryover"
								@if($category !== null && $category['carryover']) checked @endif>
							<label class="form-check-label custom-control-label"
								for="carryover">{{ $__t('Carry over what is left into the next month') }}</label>
						</div>
						<small class="form-text text-muted">{{ $__t('For things that are paid now and then - save up month by month for the car or holiday. Without it, every month starts from its plan again.') }}</small>
					</div>

					@if($mode == 'create')
					<div class="form-group">
						<label for="plan">{{ $__t('Monthly plan') }} <span class="text-muted small">({{ $__t('optional') }})</span></label>
						<div class="input-group">
							<input type="text"
								inputmode="decimal"
								autocomplete="off"
								class="form-control"
								id="plan"
								placeholder="0,00">
							<div class="input-group-append">
								<span class="input-group-text">{{ $currency }}</span>
							</div>
						</div>
						<small class="form-text text-muted">{{ $__t('Applies from this month on - it can be changed any time on the overview.') }}</small>
					</div>
					@endif
				</div>

				<div class="d-flex flex-wrap"
					style="gap: 0.5rem;">
					<button id="save-budget-category-button"
						class="btn btn-success">{{ $__t('Save') }}</button>
				</div>

			</fieldset>
		</form>
	</div>
</div>
@stop
