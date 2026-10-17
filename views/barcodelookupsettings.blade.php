@extends('layout.default')

@section('title', $__t('Barcode lookup'))

@section('content')
<div class="row">
	<div class="col">
		<h2 class="title">@yield('title')</h2>
	</div>
</div>

<hr class="my-2">

<div class="row">
	<div class="col-lg-7 col-12">
		<p class="text-muted">{{ $__t('When a scanned barcode is unknown, "E" in the product dialog looks for it - first in your other profiles, then in the free Open Facts databases (food, drugstore and household products, pet food). What it finds is shown as a proposal to confirm or correct before the product is created.') }}</p>

		<h4 class="mt-4">{{ $__t('Web search with AI') }}</h4>
		<p class="text-muted">{{ $__t('For barcodes the free sources do not know, Claude can search online shops and product databases for them. That needs an API key from Anthropic and costs a few cents per search - only when you start it.') }}</p>

		@if(!$settings['can_manage'])
		<div class="alert alert-info">{{ $__t('Only an administrator of the main profile can change this') }}</div>
		@endif

		<form id="barcodelookup-settings-form"
			novalidate>
			<fieldset @if(!$settings['can_manage']) disabled @endif>

				<div class="form-group">
					<div class="custom-control custom-checkbox">
						<input class="custom-control-input"
							type="checkbox"
							id="ai_enabled"
							@if($settings['ai_enabled']) checked @endif>
						<label class="custom-control-label"
							for="ai_enabled">{{ $__t('Offer the web search with AI') }}</label>
					</div>
				</div>

				<div class="form-group">
					<label for="api_key">{{ $__t('Anthropic API key') }}</label>
					<input type="password"
						class="form-control"
						id="api_key"
						autocomplete="off"
						placeholder="@if($settings['key_hint']){{ $__t('Stored (%s) - enter a new one to replace it', $settings['key_hint']) }}@elseif($settings['key_from_environment']){{ $__t('Taken from the environment (ANTHROPIC_API_KEY)') }}@else sk-ant-…@endif">
					<small class="form-text text-muted">{{ $__t('Created at console.anthropic.com under "API keys". It is stored in the data folder of this installation and never shown again.') }}</small>
					@if($settings['key_hint'])
					<div class="custom-control custom-checkbox mt-2">
						<input class="custom-control-input"
							type="checkbox"
							id="remove_key">
						<label class="custom-control-label"
							for="remove_key">{{ $__t('Remove the stored key') }}</label>
					</div>
					@endif
				</div>

				<div class="form-group">
					<label for="monthly_limit">{{ $__t('At most this many searches per month') }}</label>
					<input type="number"
						inputmode="numeric"
						class="form-control"
						id="monthly_limit"
						min="0"
						max="10000"
						value="{{ $settings['monthly_limit'] }}">
					<small class="form-text text-muted">{{ $__t('A guard against surprises on the bill - one search costs roughly 5 to 8 cents.') }}</small>
				</div>

				<div class="d-flex flex-wrap"
					style="gap: 0.5rem;">
					<button id="save-barcodelookup-settings-button"
						class="btn btn-success">{{ $__t('Save') }}</button>
					<button id="test-barcodelookup-key-button"
						type="button"
						class="btn btn-outline-dark"
						@if(!$settings['has_key']) disabled @endif>
						<i class="fa-solid fa-plug-circle-check"></i>&nbsp;{{ $__t('Check the key') }}
					</button>
				</div>
			</fieldset>
		</form>

		<h4 class="mt-4">{{ $__t('This month') }}</h4>
		<table class="table table-sm barcodelookup-usage">
			<tr>
				<td>{{ $__t('Searches with AI') }}</td>
				<td class="text-right">{{ $settings['usage']['lookups'] }} / {{ $settings['monthly_limit'] }}</td>
			</tr>
			<tr>
				<td>{{ $__t('Web searches made by them') }}</td>
				<td class="text-right">{{ $settings['usage']['searches'] }}</td>
			</tr>
			<tr>
				<td>{{ $__t('Estimated cost') }}</td>
				<td class="text-right">{{ number_format($estimatedCost, 2, ',', '.') }} $</td>
			</tr>
		</table>
		<p class="small text-muted">{{ $__t('Model: %s. The estimate uses the published prices; what counts is the bill in the Anthropic console.', $settings['model']) }}</p>
	</div>
</div>
@stop
