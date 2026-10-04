{{-- Household budget (Grocy Modern): previous / current / next month --}}
<div class="budget-month-nav">
	<a class="btn btn-outline-dark"
		href="{{ $U($monthUrl) }}?budget={{ $selectedBudget['id'] }}&month={{ $previousMonth }}{{ $monthQuery ?? '' }}"
		aria-label="{{ $__t('Previous month') }}">
		<i class="fa-solid fa-chevron-left"></i>
	</a>
	<div class="budget-month-label">
		@if($month !== date('Y-m'))
		<a href="{{ $U($monthUrl) }}?budget={{ $selectedBudget['id'] }}{{ $monthQuery ?? '' }}"
			title="{{ $__t('Back to the current month') }}">{{ $monthLabel($month) }}</a>
		@else
		{{ $monthLabel($month) }}
		@endif
	</div>
	<a class="btn btn-outline-dark"
		href="{{ $U($monthUrl) }}?budget={{ $selectedBudget['id'] }}&month={{ $nextMonth }}{{ $monthQuery ?? '' }}"
		aria-label="{{ $__t('Next month') }}">
		<i class="fa-solid fa-chevron-right"></i>
	</a>
</div>
