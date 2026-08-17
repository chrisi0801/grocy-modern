// Swipe-to-dismiss for the bottom sheet dialogs on phones.
//
// Below the sheet breakpoint every modal is rendered as a bottom sheet with a
// grab handle (see grocy_theme.css). This makes that handle mean something:
// drag the sheet down to close it, exactly like a native sheet.
//
// Two kinds of dialogs need to be covered:
//   1. Regular Bootstrap modals (e.g. the product card)
//   2. bootbox dialogs containing an <iframe> (all the "show as dialog" forms)
// Touch events do not cross an iframe boundary, so for the second kind the
// listeners are attached to the iframe's own document as well - which works
// because those iframes are same-origin.

var GrocySheet = {
	// Matches the bottom sheet media query in grocy_theme.css
	Breakpoint: 576,
	// How far the sheet has to be dragged before it closes
	DismissDistance: 100,
	// ...or how fast, so a short flick closes it too (px per ms)
	DismissVelocity: 0.55,
	// Ignore tiny movements, and treat clearly horizontal ones as "not a drag"
	StartSlop: 6
};

GrocySheet.State = null;

GrocySheet.IsSheet = function ()
{
	return window.innerWidth < GrocySheet.Breakpoint;
};

GrocySheet.Start = function (event, modal)
{
	GrocySheet.State = null;

	if (!GrocySheet.IsSheet() || !modal || event.touches.length !== 1)
	{
		return;
	}

	var dialog = modal.querySelector(".modal-dialog");

	if (!dialog)
	{
		return;
	}

	// Only grab the sheet when its content is scrolled to the very top,
	// otherwise the gesture belongs to the scroll container
	if (modal.scrollTop > 0)
	{
		return;
	}

	GrocySheet.State = {
		modal: modal,
		dialog: dialog,
		startX: event.touches[0].clientX,
		startY: event.touches[0].clientY,
		startTime: Date.now(),
		delta: 0,
		dragging: false
	};
};

GrocySheet.Move = function (event)
{
	var state = GrocySheet.State;

	if (!state || event.touches.length !== 1)
	{
		return;
	}

	var deltaY = event.touches[0].clientY - state.startY;
	var deltaX = event.touches[0].clientX - state.startX;

	if (!state.dragging)
	{
		if (Math.abs(deltaY) < GrocySheet.StartSlop)
		{
			return;
		}

		// Upwards or mostly sideways: not a dismiss gesture, let it be
		if (deltaY < 0 || Math.abs(deltaX) > Math.abs(deltaY))
		{
			GrocySheet.State = null;
			return;
		}

		state.dragging = true;
		state.dialog.classList.add("sheet-dragging");
	}

	// Keep the sheet from scrolling while it is being dragged
	if (event.cancelable)
	{
		event.preventDefault();
	}

	state.delta = Math.max(0, deltaY);
	state.dialog.style.transform = "translateY(" + state.delta + "px)";

	var backdrop = document.querySelector(".modal-backdrop");

	if (backdrop)
	{
		var height = state.dialog.offsetHeight || window.innerHeight;
		backdrop.style.opacity = (0.45 * Math.max(0, 1 - state.delta / height)).toString();
	}
};

GrocySheet.End = function ()
{
	var state = GrocySheet.State;
	GrocySheet.State = null;

	if (!state || !state.dragging)
	{
		return;
	}

	var velocity = state.delta / Math.max(1, Date.now() - state.startTime);

	if (state.delta >= GrocySheet.DismissDistance || velocity >= GrocySheet.DismissVelocity)
	{
		GrocySheet.Dismiss(state);
	}
	else
	{
		GrocySheet.SpringBack(state);
	}
};

GrocySheet.Cancel = function ()
{
	var state = GrocySheet.State;
	GrocySheet.State = null;

	if (state && state.dragging)
	{
		GrocySheet.SpringBack(state);
	}
};

GrocySheet.SpringBack = function (state)
{
	// Dropping the class re-enables the CSS transition, so it animates back
	state.dialog.classList.remove("sheet-dragging");
	state.dialog.style.transform = "";
	GrocySheet.ResetBackdrop();
};

GrocySheet.Dismiss = function (state)
{
	// Animate the rest of the way down first, then let Bootstrap tear the
	// modal down - hiding it straight away would make it jump back to its
	// resting position for the closing animation
	state.dialog.classList.remove("sheet-dragging");
	state.dialog.style.transform = "translateY(100%)";
	GrocySheet.ResetBackdrop();

	window.setTimeout(function ()
	{
		$(state.modal).modal("hide");
	}, 180);
};

GrocySheet.ResetBackdrop = function ()
{
	var backdrop = document.querySelector(".modal-backdrop");

	if (backdrop)
	{
		backdrop.style.opacity = "";
	}
};

// Attach the gesture to a document. `resolveModal` maps a touch event to the
// modal element in *this* window that should be dragged.
GrocySheet.Attach = function (doc, resolveModal)
{
	if (!doc || doc.grocySheetAttached)
	{
		return;
	}

	doc.grocySheetAttached = true;

	doc.addEventListener("touchstart", function (e)
	{
		GrocySheet.Start(e, resolveModal(e));
	}, { passive: true });

	doc.addEventListener("touchmove", function (e)
	{
		GrocySheet.Move(e);
	}, { passive: false });

	doc.addEventListener("touchend", function ()
	{
		GrocySheet.End();
	}, { passive: true });

	doc.addEventListener("touchcancel", function ()
	{
		GrocySheet.Cancel();
	}, { passive: true });
};

GrocySheet.AttachToIframe = function (iframe, modal)
{
	var doc = null;

	try
	{
		// Same-origin only - a foreign document would throw here
		doc = iframe.contentDocument;
	}
	catch (e)
	{
		return;
	}

	if (!doc)
	{
		return;
	}

	GrocySheet.Attach(doc, function ()
	{
		return modal;
	});
};

// Only the topmost window drives the gesture; embedded views get their
// document wired up by their parent
if (window.self === window.top)
{
	GrocySheet.Attach(document, function (e)
	{
		return e.target && e.target.closest ? e.target.closest(".modal") : null;
	});

	$(document).on("shown.bs.modal", ".modal", function ()
	{
		var modal = this;

		$(modal).find("iframe").each(function ()
		{
			var iframe = this;

			GrocySheet.AttachToIframe(iframe, modal);

			// The iframe may still be loading, and it can navigate afterwards
			$(iframe).on("load", function ()
			{
				GrocySheet.AttachToIframe(iframe, modal);
			});
		});
	});

	// Clean up whatever the gesture left behind
	$(document).on("hidden.bs.modal", ".modal", function ()
	{
		var dialog = this.querySelector(".modal-dialog");

		if (dialog)
		{
			dialog.classList.remove("sheet-dragging");
			dialog.style.transform = "";
		}

		GrocySheet.ResetBackdrop();
	});
}

// --------------------------------------------------------------------------
// Row action menus as bottom sheets
// --------------------------------------------------------------------------
// The menu itself is turned into a sheet by grocy_mobile.css; this adds the
// dimmed layer behind it. Tapping that layer closes the menu, because
// Bootstrap closes an open dropdown on any click outside of it.

GrocySheet.RemoveDropdownBackdrop = function ()
{
	var backdrop = document.querySelector(".dropdown-sheet-backdrop");

	if (backdrop && backdrop.parentNode)
	{
		backdrop.parentNode.removeChild(backdrop);
	}
};

$(document).on("show.bs.dropdown", "td.dt-cell-actions .dropdown", function ()
{
	// Card mode only - see the breakpoint in grocy_mobile.css
	if (window.innerWidth >= 768 || document.querySelector(".dropdown-sheet-backdrop"))
	{
		return;
	}

	var backdrop = document.createElement("div");
	backdrop.className = "dropdown-sheet-backdrop";
	document.body.appendChild(backdrop);

	// The menu lives inside `td.dt-cell-actions`, which has a z-index and
	// therefore its own stacking context - the menu can never escape it. So
	// the *cell* has to be lifted above the backdrop, otherwise the corner
	// buttons of the rows below paint on top of the open menu.
	var cell = this.closest("td.dt-cell-actions");

	if (cell)
	{
		cell.classList.add("dropdown-sheet-open");
	}
});

$(document).on("hidden.bs.dropdown", "td.dt-cell-actions .dropdown", function ()
{
	GrocySheet.RemoveDropdownBackdrop();

	var cell = this.closest("td.dt-cell-actions");

	if (cell)
	{
		cell.classList.remove("dropdown-sheet-open");
	}
});
