@extends('layout.default')

@php
$isTransfer = $transaction !== null && !empty($transaction['transfer_id']);
@endphp

@if($isTransfer)
@section('title', $__t('Edit transfer'))
@elseif($mode == 'edit')
@section('title', $__t('Edit transaction'))
@else
@section('title', $__t('New transaction'))
@endif

@section('content')
@include('components.budgetassets')

@php
$type = $transaction !== null ? ($transaction['amount'] > 0 ? 'income' : 'expense') : $initialType;
$amount = $transaction !== null ? abs($transaction['amount']) / 100 : null;
$selectedBudgetId = (int)$selectedBudget['id'];
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
				TransactionId: {{ $transaction !== null ? $transaction['id'] : 'null' }},
				IsTransfer: {{ $isTransfer ? 'true' : 'false' }},
				CategoriesByBudget: @json($categoriesByBudget),
				SelectedCategoryId: {{ $transaction !== null && $transaction['category_id'] !== null ? $transaction['category_id'] : 'null' }},
				ReturnUrl: @json($U('/budget/transactions?budget=' . $selectedBudgetId))
			};
		</script>

		@if(!$canEdit)
		<div class="budget-readonly-note">
			<i class="fa-solid fa-eye mt-1"></i>
			<div>{{ $__t('This is the personal budget of %s - you can look at it, but only they can change it.', $selectedBudget['owner_name'] ?? '?') }}</div>
		</div>
		@endif

		@if($isTransfer)
		<div class="budget-readonly-note">
			<i class="fa-solid fa-right-left mt-1"></i>
			<div>
				@if($transaction['amount'] < 0)
				{{ $__t('Transfer from %1$s to %2$s', $selectedBudget['name'], $counterpartBudget['name'] ?? '?') }}
				@else
				{{ $__t('Transfer from %1$s to %2$s', $counterpartBudget['name'] ?? '?', $selectedBudget['name']) }}
				@endif
				<div class="small">{{ $__t('Amount, date and note change on both sides, the category only here.') }}</div>
			</div>
		</div>
		@endif

		<form id="budget-transaction-form"
			novalidate>
			<fieldset @if(!$canEdit) disabled @endif>

				@if(!$isTransfer)
				<div class="budget-type-switch"
					role="radiogroup"
					aria-label="{{ $__t('Type') }}">
					<input type="radio"
						name="type"
						id="type-expense"
						value="expense"
						@if($type === 'expense') checked @endif>
					<label for="type-expense"><i class="fa-solid fa-minus"></i>&nbsp;{{ $__t('Expense') }}</label>
					<input type="radio"
						name="type"
						id="type-income"
						value="income"
						@if($type === 'income') checked @endif>
					<label for="type-income"><i class="fa-solid fa-plus"></i>&nbsp;{{ $__t('Deposit') }}</label>
				</div>
				@endif

				<div class="form-group">
					<label for="amount">{{ $__t('Sum') }}</label>
					<div class="input-group">
						<input type="text"
							inputmode="decimal"
							autocomplete="off"
							class="form-control budget-amount-input"
							required
							id="amount"
							value="@if($amount !== null){{ number_format($amount, 2, ',', '') }}@endif"
							placeholder="0,00">
						<div class="input-group-append">
							<span class="input-group-text">{{ $currency }}</span>
						</div>
						<div class="invalid-feedback">{{ $__t('Please enter an amount') }}</div>
					</div>
				</div>

				<div class="form-group">
					<label for="date">{{ $__t('Date') }}</label>
					<input type="date"
						class="form-control"
						required
						id="date"
						value="{{ $transaction['date'] ?? date('Y-m-d') }}">
					<div class="invalid-feedback">{{ $__t('A date is required') }}</div>
				</div>

				@if(!$isTransfer)
				<div class="form-group @if(count($budgets) < 2) d-none @endif">
					<label for="budget_id">{{ $__t('Budget') }}</label>
					<select class="custom-control custom-select"
						id="budget_id">
						@foreach($budgets as $budget)
						<option value="{{ $budget['id'] }}"
							@if((int)$budget['id'] === $selectedBudgetId) selected @endif>{{ $budget['name'] }}</option>
						@endforeach
					</select>
				</div>
				@else
				<input type="hidden"
					id="budget_id"
					value="{{ $selectedBudgetId }}">
				@endif

				<div class="form-group">
					<label for="category_id">{{ $__t('Category') }}</label>
					<select class="custom-control custom-select"
						id="category_id"></select>
					@if(!$isTransfer)
					<small id="refund-hint"
						class="form-text text-muted d-none">{{ $__t('Money back for something you bought? Pick its expense category - the refund then lowers what was spent there.') }}</small>
					@endif
				</div>

				@if(!$isTransfer)
				<div class="form-group">
					<label for="payee">{{ $__t('Payee') }}</label>
					<input type="text"
						class="form-control"
						id="payee"
						list="payee-list"
						autocomplete="off"
						value="{{ $transaction['payee'] ?? '' }}">
					<datalist id="payee-list">
						@foreach($payees as $payee)
						<option value="{{ $payee }}"></option>
						@endforeach
					</datalist>
				</div>
				@endif

				<div class="form-group">
					<label for="note">{{ $__t('Note') }}</label>
					<input type="text"
						class="form-control"
						id="note"
						value="{{ $transaction['note'] ?? '' }}">
				</div>

				<div class="d-flex flex-wrap"
					style="gap: 0.5rem;">
					<button id="save-budget-transaction-button"
						class="btn btn-success">{{ $__t('Save') }}</button>
					@if($mode == 'edit')
					<button id="delete-budget-transaction-button"
						type="button"
						class="btn btn-outline-danger ml-auto"
						data-name="{{ $transaction['payee'] ?: ($transaction['category_name'] ?? $transaction['note'] ?? '') }}">
						<i class="fa-solid fa-trash"></i>&nbsp;{{ $__t('Delete') }}
					</button>
					@endif
				</div>

			</fieldset>
		</form>

		@if($transaction !== null && !empty($transaction['recurring_id']))
		<p class="small text-muted mt-3">
			<i class="fa-solid fa-arrows-rotate"></i>
			{{ $__t('Booked by a recurring transaction. Changing it here changes only this booking.') }}
		</p>
		@endif
	</div>
</div>
@stop
