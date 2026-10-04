{{-- Household budget (Grocy Modern): page title and the links between the budget pages --}}
@php
$budgetQuery = isset($budgetId) && $budgetId !== null ? '?budget=' . $budgetId : '';
$budgetLinks = [
	'overview' => ['/budget', 'Overview', 'fa-wallet'],
	'transactions' => ['/budget/transactions', 'Transactions', 'fa-list'],
	'recurring' => ['/budget/recurring', 'Recurring', 'fa-arrows-rotate'],
	'reports' => ['/budget/reports', 'Reports', 'fa-chart-column'],
	'settings' => ['/budget/settings', 'Settings', 'fa-sliders'],
];
@endphp
<div class="row">
	<div class="col">
		<div class="title-related-links">
			<h2 class="title mr-2 order-0">
				@yield('title')
			</h2>
			<h2 class="mb-0 mr-auto order-3 order-md-1 width-xs-sm-100"></h2>
			@if(!$embedded)
			<button class="btn btn-outline-dark d-md-none mt-2 float-right order-1 order-md-3"
				type="button"
				data-toggle="collapse"
				data-target="#related-links">
				<i class="fa-solid fa-ellipsis-v"></i>
			</button>
			<div class="related-links collapse d-md-flex order-2 width-xs-sm-100"
				id="related-links">
				@foreach($budgetLinks as $key => $link)
				@if($key !== $active)
				<a class="btn btn-outline-dark responsive-button m-1 mt-md-0 mb-md-0 float-right"
					href="{{ $U($link[0]) }}{{ $budgetQuery }}">
					<i class="fa-solid {{ $link[2] }}"></i>&nbsp;{{ $__t($link[1]) }}
				</a>
				@endif
				@endforeach
			</div>
			@endif
		</div>
	</div>
</div>
