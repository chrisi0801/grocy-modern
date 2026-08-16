{{-- Mobile bottom tab bar - only rendered for authenticated, non-embedded views --}}
<nav id="bottom-nav"
	aria-label="{{ $__t('Menu') }}">

	@if(GROCY_FEATURE_FLAG_STOCK)
	<a class="bottom-nav-item @if($viewName == 'stockoverview') active @endif"
		href="{{ $U('/stockoverview') }}">
		<i class="fa-solid fa-box"></i>
		<span class="bottom-nav-label">{{ $__t('Stock') }}</span>
	</a>
	@endif

	@if(GROCY_FEATURE_FLAG_SHOPPINGLIST)
	<a class="bottom-nav-item permission-SHOPPINGLIST @if($viewName == 'shoppinglist') active @endif"
		href="{{ $U('/shoppinglist') }}">
		<i class="fa-solid fa-shopping-cart"></i>
		<span class="bottom-nav-label">{{ $__t('Shopping list') }}</span>
	</a>
	@endif

	@if(GROCY_FEATURE_FLAG_STOCK)
	<div class="dropdown dropup">
		<a class="bottom-nav-fab"
			href="#"
			role="button"
			data-toggle="dropdown"
			data-display="static"
			aria-haspopup="true"
			aria-expanded="false"
			aria-label="{{ $__t('Quick actions') }}">
			<i class="fa-solid fa-plus"></i>
		</a>
		<div class="dropdown-menu">
			<a class="dropdown-item permission-STOCK_PURCHASE"
				href="{{ $U('/purchase') }}"><i class="fa-solid fa-fw fa-cart-plus"></i>&nbsp;{{ $__t('Purchase') }}</a>
			<a class="dropdown-item permission-STOCK_CONSUME"
				href="{{ $U('/consume') }}"><i class="fa-solid fa-fw fa-utensils"></i>&nbsp;{{ $__t('Consume') }}</a>
			@if(GROCY_FEATURE_FLAG_STOCK_LOCATION_TRACKING)
			<a class="dropdown-item permission-STOCK_TRANSFER"
				href="{{ $U('/transfer') }}"><i class="fa-solid fa-fw fa-exchange-alt"></i>&nbsp;{{ $__t('Transfer') }}</a>
			@endif
			<a class="dropdown-item permission-STOCK_INVENTORY"
				href="{{ $U('/inventory') }}"><i class="fa-solid fa-fw fa-list"></i>&nbsp;{{ $__t('Inventory') }}</a>
			@if(GROCY_FEATURE_FLAG_SHOPPINGLIST)
			<div class="dropdown-divider"></div>
			<a class="dropdown-item show-as-dialog-link permission-SHOPPINGLIST_ITEMS_ADD"
				href="{{ $U('/shoppinglistitem/new?embedded') }}"><i class="fa-solid fa-fw fa-shopping-cart"></i>&nbsp;{{ $__t('Add to shopping list') }}</a>
			@endif
		</div>
	</div>
	@endif

	@if(GROCY_FEATURE_FLAG_CHORES)
	<a class="bottom-nav-item @if($viewName == 'choresoverview') active @endif"
		href="{{ $U('/choresoverview') }}">
		<i class="fa-solid fa-home"></i>
		<span class="bottom-nav-label">{{ $__t('Chores') }}</span>
	</a>
	@elseif(GROCY_FEATURE_FLAG_TASKS)
	<a class="bottom-nav-item permission-TASKS @if($viewName == 'tasks') active @endif"
		href="{{ $U('/tasks') }}">
		<i class="fa-solid fa-tasks"></i>
		<span class="bottom-nav-label">{{ $__t('Tasks') }}</span>
	</a>
	@elseif(GROCY_FEATURE_FLAG_RECIPES)
	<a class="bottom-nav-item permission-RECIPES @if($viewName == 'recipes') active @endif"
		href="{{ $U('/recipes') }}">
		<i class="fa-solid fa-pizza-slice"></i>
		<span class="bottom-nav-label">{{ $__t('Recipes') }}</span>
	</a>
	@endif

	<button id="bottom-nav-more"
		class="bottom-nav-item"
		type="button"
		aria-controls="sidebarResponsive"
		aria-expanded="false">
		<i class="fa-solid fa-bars"></i>
		<span class="bottom-nav-label">{{ $__t('More') }}</span>
	</button>
</nav>
