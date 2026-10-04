@php require_frontend_packages(['datatables']); @endphp

@extends('layout.default')

@section('title', $__t('Recurring transactions'))

@section('content')
@include('components.budgetassets')
@include('components.budgetheader', ['active' => 'recurring', 'budgetId' => $selectedBudget['id']])

@php
$intervals = [1 => $__t('Monthly'), 3 => $__t('Quarterly'), 6 => $__t('Half-yearly'), 12 => $__t('Yearly')];
@endphp

@include('components.budgetswitcher', ['switcherUrl' => '/budget/recurring'])

<p class="text-muted small">{{ $__t('Rent, salary, insurance, pocket money: whatever comes back regularly is booked automatically when it is due.') }}</p>

@if($selectedBudget['can_edit'])
<div class="mb-3">
	<a class="btn btn-primary show-as-dialog-link"
		href="{{ $U('/budget/recurring/new?embedded&budget=' . $selectedBudget['id']) }}">
		<i class="fa-solid fa-plus"></i>&nbsp;{{ $__t('Add') }}
	</a>
</div>
@endif

<div class="row">
	<div class="col">
		<table id="budget-recurring-table"
			class="table table-sm table-striped nowrap w-100">
			<thead>
				<tr>
					<th class="border-right"></th>
					<th>{{ $__t('Name') }}</th>
					<th>{{ $__t('Sum') }}</th>
					<th>{{ $__t('Interval') }}</th>
					<th>{{ $__t('Next booking') }}</th>
					<th class="allow-grouping">{{ $__t('Category') }}</th>
					<th>{{ $__t('Ends') }}</th>
				</tr>
			</thead>
			<tbody class="d-none">
				@foreach($rules as $rule)
				@php
				$isTransfer = !empty($rule['target_budget_id']);
				$incoming = $isTransfer && (int)$rule['target_budget_id'] === (int)$selectedBudget['id'];
				$signed = $isTransfer ? ($incoming ? $rule['amount'] : -$rule['amount']) : $rule['amount'];
				@endphp
				<tr class="@if(!$rule['active']) text-muted @endif">
					<td class="fit-content border-right">
						<a class="btn btn-info btn-sm show-as-dialog-link @if(!$rule['can_edit']) disabled @endif"
							href="{{ $U('/budget/recurring/' . $rule['id'] . '?embedded') }}"
							data-toggle="tooltip"
							title="{{ $__t('Edit this item') }}">
							<i class="fa-solid fa-edit"></i>
						</a>
						<a class="btn btn-danger btn-sm budget-recurring-delete-button @if(!$rule['can_edit']) disabled @endif"
							href="#"
							data-recurring-id="{{ $rule['id'] }}"
							data-recurring-name="{{ $rule['name'] }}"
							data-toggle="tooltip"
							title="{{ $__t('Delete this item') }}">
							<i class="fa-solid fa-trash"></i>
						</a>
					</td>
					<td>
						@if($isTransfer)
						<i class="fa-solid fa-right-left text-muted"></i>
						@endif
						{{ $rule['name'] }}
						@if(!$rule['active'])
						<span class="budget-badge">{{ $__t('Paused') }}</span>
						@endif
					</td>
					<td data-order="{{ $signed }}">
						<span class="budget-tx-amount @if($signed > 0) budget-positive @endif">{{ $money($signed) }}</span>
					</td>
					<td>{{ $intervals[(int)$rule['interval_months']] ?? '' }}, {{ $__t('on the %s.', $rule['day_of_month']) }}</td>
					<td data-order="{{ $rule['active'] ? $rule['next_due_date'] : '9999' }}">
						@if($rule['active'])
						{{ $rule['next_due_date'] }}
						<time class="timeago timeago-contextual timeago-date-only"
							datetime="{{ $rule['next_due_date'] }}"></time>
						@else
						-
						@endif
					</td>
					<td>
						@if($isTransfer)
						{{ $incoming ? $__t('From %s', $rule['budget_name']) : $__t('To %s', $rule['target_budget_name']) }}
						@else
						{{ $rule['category_name'] ?? '' }}
						@endif
					</td>
					<td>{{ $rule['end_date'] ?? '' }}</td>
				</tr>
				@endforeach
			</tbody>
		</table>
	</div>
</div>
@stop
