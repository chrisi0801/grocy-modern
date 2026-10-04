{{-- Household budget (Grocy Modern): one pill per budget, the shared ones first --}}
@php $switcherQuery = $switcherQuery ?? ''; @endphp
<div class="budget-switcher"
	role="tablist"
	aria-label="{{ $__t('Budgets') }}">
	@foreach($budgets as $budget)
	<a class="btn btn-sm @if((int)$budget['id'] === (int)$selectedBudget['id']) btn-primary @else btn-outline-dark @endif"
		role="tab"
		aria-selected="{{ (int)$budget['id'] === (int)$selectedBudget['id'] ? 'true' : 'false' }}"
		href="{{ $U($switcherUrl) }}?budget={{ $budget['id'] }}{{ $switcherQuery }}">
		@if(empty($budget['owner_user_id']))
		<i class="fa-solid fa-house"></i>
		@else
		<i class="fa-solid fa-user"></i>
		@endif
		{{ $budget['name'] }}
	</a>
	@endforeach
</div>
