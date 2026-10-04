@php require_frontend_packages(['datatables']); @endphp

@extends('layout.default')

@section('title', $__t('Transactions'))

@section('content')
@include('components.budgetassets')
@include('components.budgetheader', ['active' => 'transactions', 'budgetId' => $selectedBudget['id']])

@php
$canEdit = $selectedBudget['can_edit'];
$categoryQuery = $selectedCategoryId !== null ? '&category=' . $selectedCategoryId : '';
$incomeSum = array_sum(array_map(fn ($t) => max(0, $t['amount']), $transactions));
$expenseSum = array_sum(array_map(fn ($t) => min(0, $t['amount']), $transactions));
@endphp

@include('components.budgetswitcher', ['switcherUrl' => '/budget/transactions', 'switcherQuery' => '&month=' . $month])
@include('components.budgetmonthnav', ['monthUrl' => '/budget/transactions', 'monthQuery' => $categoryQuery])

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

<div class="row">
	<div class="col-12 col-md-6 col-xl-4 mb-2">
		<div class="input-group">
			<div class="input-group-prepend">
				<span class="input-group-text"><i class="fa-solid fa-search"></i></span>
			</div>
			<input type="text"
				id="search"
				class="form-control"
				placeholder="{{ $__t('Search') }}">
		</div>
	</div>
	<div class="col-12 col-md-6 col-xl-4 mb-2">
		<div class="input-group">
			<div class="input-group-prepend">
				<span class="input-group-text"><i class="fa-solid fa-filter"></i>&nbsp;{{ $__t('Category') }}</span>
			</div>
			<select class="custom-control custom-select"
				id="category-filter">
				<option value="">{{ $__t('All') }}</option>
				@foreach($categories as $category)
				<option value="{{ $category['id'] }}"
					@if($selectedCategoryId === (int)$category['id']) selected @endif>{{ $category['name'] }}</option>
				@endforeach
			</select>
		</div>
	</div>
</div>

<div class="budget-summary-line">
	<span>{{ $__n(count($transactions), '%s transaction', '%s transactions') }}</span>
	<span class="budget-positive">{{ $money($incomeSum) }}</span>
	<span>{{ $money($expenseSum) }}</span>
</div>

<div class="row">
	<div class="col">
		<table id="budget-transactions-table"
			class="table table-sm table-striped nowrap w-100">
			<thead>
				<tr>
					<th class="border-right"></th>
					<th>{{ $__t('Description') }}</th>
					<th>{{ $__t('Sum') }}</th>
					<th>{{ $__t('Date') }}</th>
					<th class="allow-grouping">{{ $__t('Category') }}</th>
					<th>{{ $__t('Note') }}</th>
					<th class="allow-grouping">{{ $__t('Booked by') }}</th>
				</tr>
			</thead>
			<tbody class="d-none">
				@foreach($transactions as $transaction)
				@php
				$isTransfer = !empty($transaction['transfer_id']);
				if ($isTransfer)
				{
					$description = $transaction['amount'] < 0
						? $__t('Transfer to %s', $transaction['transfer_budget_name'] ?? '?')
						: $__t('Transfer from %s', $transaction['transfer_budget_name'] ?? '?');
				}
				else
				{
					$description = $transaction['payee'] ?: ($transaction['category_name'] ?: ($transaction['note'] ?: $__t('Without category')));
				}
				@endphp
				<tr>
					<td class="fit-content border-right">
						<a class="btn btn-info btn-sm show-as-dialog-link @if(!$transaction['can_edit']) disabled @endif"
							href="{{ $U('/budget/transaction/' . $transaction['id'] . '?embedded') }}"
							data-toggle="tooltip"
							title="{{ $__t('Edit this item') }}">
							<i class="fa-solid fa-edit"></i>
						</a>
						<a class="btn btn-danger btn-sm budget-transaction-delete-button @if(!$transaction['can_edit']) disabled @endif"
							href="#"
							data-transaction-id="{{ $transaction['id'] }}"
							data-transaction-name="{{ $description }}"
							data-is-transfer="{{ $isTransfer ? 1 : 0 }}"
							data-toggle="tooltip"
							title="{{ $__t('Delete this item') }}">
							<i class="fa-solid fa-trash"></i>
						</a>
					</td>
					<td>
						@if($isTransfer)
						<i class="fa-solid fa-right-left text-muted"></i>
						@endif
						@if(!empty($transaction['recurring_id']))
						<i class="fa-solid fa-arrows-rotate text-muted"
							title="{{ $__t('Booked by a recurring transaction') }}"></i>
						@endif
						{{ $description }}
					</td>
					<td data-order="{{ $transaction['amount'] }}">
						<span class="budget-tx-amount @if($transaction['amount'] > 0) budget-positive @endif">{{ $money($transaction['amount']) }}</span>
					</td>
					<td data-order="{{ $transaction['date'] }}-{{ str_pad($transaction['id'], 10, '0', STR_PAD_LEFT) }}">
						{{ $transaction['date'] }}
						<time class="timeago timeago-contextual timeago-date-only"
							datetime="{{ $transaction['date'] }}"></time>
					</td>
					{{-- On a card the category would repeat the headline --}}
					<td class="@if($description === $transaction['category_name']) budget-repeats-title @endif">{{ $transaction['category_name'] ?? '' }}</td>
					<td>{{ $transaction['note'] ?? '' }}</td>
					<td>{{ $transaction['user_name'] ?? '' }}</td>
				</tr>
				@endforeach
			</tbody>
		</table>
	</div>
</div>

<script>
	var BudgetPage = {
		BudgetId: {{ $selectedBudget['id'] }},
		Month: '{{ $month }}'
	};
</script>
@stop
