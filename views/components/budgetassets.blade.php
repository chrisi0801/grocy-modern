{{-- Household budget (Grocy Modern): styles and helpers of the budget pages --}}
@once
@push('pageStyles')
<link href="{{ $U('/css/grocy_budget.css?v=', true) }}{{ $version }}"
	rel="stylesheet">
@endpush
@push('pageScripts')
<script src="{{ $U('/js/grocy_budget.js?v=', true) }}{{ $version }}"></script>
@endpush
@endonce
