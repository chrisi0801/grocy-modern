@extends('layout.default')

@section('title', $__t('Budget transfer'))

@section('content')
@include('components.budgetassets')

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
				CategoriesByBudget: @json($categoriesByBudget),
				PocketMoneyName: @json($pocketMoneyName)
			};
		</script>

		@if(count($editableBudgets) === 0)
		<div class="budget-readonly-note">
			<i class="fa-solid fa-eye mt-1"></i>
			<div>{{ $__t('There is no budget you could move money out of.') }}</div>
		</div>
		@else
		<p class="text-muted small">{{ $__t('Moves money from one budget into another - for example pocket money from the household into a personal budget. It leaves the one as an expense and arrives in the other as income.') }}</p>

		<form id="budget-transfer-form"
			novalidate>

			<div class="form-group">
				<label for="amount">{{ $__t('Sum') }}</label>
				<div class="input-group">
					<input type="text"
						inputmode="decimal"
						autocomplete="off"
						class="form-control budget-amount-input"
						required
						id="amount"
						placeholder="0,00">
					<div class="input-group-append">
						<span class="input-group-text">{{ $currency }}</span>
					</div>
					<div class="invalid-feedback">{{ $__t('Please enter an amount') }}</div>
				</div>
			</div>

			<div class="form-row">
				<div class="form-group col-md-6">
					<label for="from_budget_id">{{ $__t('From budget') }}</label>
					<select class="custom-control custom-select"
						id="from_budget_id">
						@foreach($editableBudgets as $budget)
						<option value="{{ $budget['id'] }}"
							@if((int)$budget['id'] === (int)$fromBudget['id']) selected @endif>{{ $budget['name'] }}</option>
						@endforeach
					</select>
				</div>
				<div class="form-group col-md-6">
					<label for="from_category_id">{{ $__t('Booked as') }}</label>
					<select class="custom-control custom-select"
						id="from_category_id"></select>
				</div>
			</div>

			<div class="form-row">
				<div class="form-group col-md-6">
					<label for="to_budget_id">{{ $__t('To budget') }}</label>
					<select class="custom-control custom-select"
						required
						id="to_budget_id">
						@foreach($budgets as $budget)
						<option value="{{ $budget['id'] }}"
							@if($toBudgetId === (int)$budget['id']) selected @endif>{{ $budget['name'] }}</option>
						@endforeach
					</select>
					<div class="invalid-feedback">{{ $__t('A transfer needs two different budgets') }}</div>
				</div>
				<div class="form-group col-md-6">
					<label for="to_category_id">{{ $__t('Booked as') }}</label>
					<select class="custom-control custom-select"
						id="to_category_id"></select>
				</div>
			</div>

			<div class="form-group">
				<label for="date">{{ $__t('Date') }}</label>
				<input type="date"
					class="form-control"
					required
					id="date"
					value="{{ date('Y-m-d') }}">
				<div class="invalid-feedback">{{ $__t('A date is required') }}</div>
			</div>

			<div class="form-group">
				<label for="note">{{ $__t('Note') }}</label>
				<input type="text"
					class="form-control"
					id="note">
			</div>

			<button id="save-budget-transfer-button"
				class="btn btn-success">{{ $__t('Save') }}</button>
		</form>
		@endif
	</div>
</div>
@stop
