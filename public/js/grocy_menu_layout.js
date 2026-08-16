// App shell behaviour: mobile navigation drawer, desktop sidebar rail and the
// bottom tab bar.

var GrocyNav = {
	DrawerBreakpoint: 992
};

// --------------------------------------------------------------------------
// Mobile navigation drawer
// --------------------------------------------------------------------------

GrocyNav.OpenDrawer = function ()
{
	$("body").addClass("nav-drawer-open");
	$("#nav-drawer-toggle, #bottom-nav-more").attr("aria-expanded", "true");

	// Make sure the current page is in view when the drawer opens
	GrocyNav.ScrollActiveItemIntoView();
};

GrocyNav.CloseDrawer = function ()
{
	$("body").removeClass("nav-drawer-open");
	$("#nav-drawer-toggle, #bottom-nav-more").attr("aria-expanded", "false");
};

GrocyNav.ToggleDrawer = function ()
{
	if ($("body").hasClass("nav-drawer-open"))
	{
		GrocyNav.CloseDrawer();
	}
	else
	{
		GrocyNav.OpenDrawer();
	}
};

GrocyNav.ScrollActiveItemIntoView = function ()
{
	var activeMenuItem = $("li.active-page").first();

	if (activeMenuItem.length > 0 && !activeMenuItem.isVisibleInViewport(75))
	{
		activeMenuItem[0].scrollIntoView({ block: "center" });
	}
};

$(document).on("click", "#nav-drawer-toggle, #bottom-nav-more", function (e)
{
	e.preventDefault();
	GrocyNav.ToggleDrawer();
});

$(document).on("click", "#nav-drawer-close, #nav-drawer-backdrop", function (e)
{
	e.preventDefault();
	GrocyNav.CloseDrawer();
});

// Following a link closes the drawer, so the transition to the new page
// doesn't start with an open overlay
$(document).on("click", ".app-sidebar .navbar-sidenav a[href]:not(.nav-link-collapse)", function ()
{
	GrocyNav.CloseDrawer();
});

$(document).on("keydown", function (e)
{
	if (e.key === "Escape" && $("body").hasClass("nav-drawer-open"))
	{
		GrocyNav.CloseDrawer();
	}
});

// Swipe the drawer away
(function ()
{
	var touchStartX = null;
	var touchStartY = null;

	$(document).on("touchstart", "#sidebarResponsive", function (e)
	{
		if (!$("body").hasClass("nav-drawer-open"))
		{
			return;
		}

		touchStartX = e.originalEvent.touches[0].clientX;
		touchStartY = e.originalEvent.touches[0].clientY;
	});

	$(document).on("touchend", "#sidebarResponsive", function (e)
	{
		if (touchStartX === null)
		{
			return;
		}

		var deltaX = e.originalEvent.changedTouches[0].clientX - touchStartX;
		var deltaY = e.originalEvent.changedTouches[0].clientY - touchStartY;

		// Clearly horizontal swipe to the left
		if (deltaX < -60 && Math.abs(deltaX) > Math.abs(deltaY) * 1.5)
		{
			GrocyNav.CloseDrawer();
		}

		touchStartX = null;
		touchStartY = null;
	});
})();

// --------------------------------------------------------------------------
// Desktop sidebar rail
// --------------------------------------------------------------------------

$('.navbar-sidenav [data-toggle="tooltip"]').tooltip({
	template: '<div class="tooltip navbar-sidenav-tooltip"><div class="arrow"></div><div class="tooltip-inner"></div></div>'
});

$("#sidenavToggler").on("click", function (e)
{
	e.preventDefault();

	$("body").toggleClass("sidenav-toggled");
	$(".navbar-sidenav .nav-link-collapse").addClass("collapsed");
	$(".navbar-sidenav .sidenav-second-level, .navbar-sidenav .sidenav-third-level").removeClass("show");

	if ($("body").hasClass("sidenav-toggled"))
	{
		window.localStorage.setItem("sidebar_state", "collapsed");
	}
	else
	{
		window.localStorage.setItem("sidebar_state", "expanded");
	}
});

$(".navbar-sidenav .nav-link-collapse").on("click", function ()
{
	$("body").removeClass("sidenav-toggled");
	window.localStorage.setItem("sidebar_state", "expanded");
});

if (window.localStorage.getItem("sidebar_state") === "collapsed")
{
	$("body").addClass("sidenav-toggled");
}

// --------------------------------------------------------------------------
// Viewport changes
// --------------------------------------------------------------------------

$(window).on("resize", function ()
{
	if (window.innerWidth >= GrocyNav.DrawerBreakpoint)
	{
		GrocyNav.CloseDrawer();
	}
});

$(function ()
{
	GrocyNav.ScrollActiveItemIntoView();
});
