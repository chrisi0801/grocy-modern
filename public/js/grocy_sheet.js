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

// How far the document the touch happened in is scrolled - 0 for a touch in
// this document, which has no scroll of its own while a modal is open
GrocySheet.TouchedDocumentScrollTop = function (event)
{
	var doc = event.target ? event.target.ownerDocument : null;

	if (!doc || doc === document)
	{
		return 0;
	}

	var scroller = doc.scrollingElement || doc.documentElement;

	return scroller ? scroller.scrollTop : 0;
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
	// otherwise the gesture belongs to the scroll container. On a phone an
	// embedded view fills the sheet and scrolls itself, so that document
	// counts as well - it is the one under the finger.
	if (modal.scrollTop > 0 || GrocySheet.Scroller(modal).scrollTop > 0
		|| GrocySheet.TouchedDocumentScrollTop(event) > 0)
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

// Dialogs whose body is an embedded view (an iframe grocy.js sizes to its full
// content height) are bounded to the screen and scroll inside - see
// grocy_theme.css. Marking them here rather than in CSS keeps it working
// without `:has()`, and only these dialogs are affected: everything inside
// them lives in the iframe, so no dropdown of this document can be clipped.
$(document).on("show.bs.modal", ".modal", function ()
{
	// Toggled, not added: a modal element can be reused for something that is
	// not an embedded view (the product card is the same element every time)
	this.classList.toggle("modal-embedded-view", !!this.querySelector(".modal-body iframe.embed-responsive"));
});

// The element that actually scrolls: for a bounded dialog that is the body,
// for every other modal the modal itself
GrocySheet.Scroller = function (modal)
{
	if (modal.classList.contains("modal-embedded-view"))
	{
		return modal.querySelector(".modal-body") || modal;
	}

	return modal;
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
// grocy_mobile.css turns the menu into a sheet; everything that needs to know
// the actual state of the browser happens here: the dimmed layer behind it, a
// height that fits what is really visible on the screen, and the same
// drag-down-to-close gesture the modal sheets have.

// Card mode, same breakpoint as in grocy_mobile.css
GrocySheet.DropdownBreakpoint = 768;
// How much of the visible viewport the sheet may take up
GrocySheet.DropdownHeightRatio = 0.6;

GrocySheet.DropdownState = null;

GrocySheet.IsDropdownSheet = function ()
{
	return window.innerWidth < GrocySheet.DropdownBreakpoint;
};

// iOS knows three viewport heights and `100vh` is the largest of them - the
// one that assumes the browser bars are collapsed. A sheet sized in vh
// therefore reaches above the visible area, which is what cut the first entry
// off. `visualViewport` is what the user can actually see, right now.
GrocySheet.VisibleHeight = function ()
{
	if (window.visualViewport && window.visualViewport.height)
	{
		return window.visualViewport.height;
	}

	return window.innerHeight;
};

GrocySheet.RemoveDropdownBackdrop = function ()
{
	var backdrop = document.querySelector(".dropdown-sheet-backdrop");

	if (backdrop && backdrop.parentNode)
	{
		backdrop.parentNode.removeChild(backdrop);
	}
};

GrocySheet.SetDropdownBackdropOpacity = function (value)
{
	var backdrop = document.querySelector(".dropdown-sheet-backdrop");

	if (backdrop)
	{
		backdrop.style.opacity = value === null ? '' : value.toString();
	}
};

// The stylesheet pins the sheet down with `transform: none !important` to get
// rid of Popper's positioning, so the drag has to be important as well
GrocySheet.SetDropdownOffset = function (menu, offset)
{
	if (offset === null)
	{
		menu.style.removeProperty("transform");
		return;
	}

	menu.style.setProperty("transform", "translateY(" + offset + ")", "important");
};

GrocySheet.CloseDropdown = function (dropdown)
{
	var toggle = dropdown.querySelector('[data-toggle="dropdown"]');

	if (toggle)
	{
		// The menu is open, so this closes it - and firing it through
		// Bootstrap means hidden.bs.dropdown runs and cleans everything up
		$(toggle).dropdown("toggle");
	}
};

GrocySheet.ResetDropdown = function (menu)
{
	GrocySheet.DropdownState = null;

	menu.classList.remove("sheet-dragging");
	menu.style.removeProperty("transform");
	menu.style.removeProperty("max-height");
};

GrocySheet.DropdownStart = function (event, menu, dropdown)
{
	GrocySheet.DropdownState = null;

	if (event.touches.length !== 1)
	{
		return;
	}

	// Only grab the sheet while it is scrolled to the very top, otherwise the
	// gesture belongs to the list of entries
	if (menu.scrollTop > 0)
	{
		return;
	}

	GrocySheet.DropdownState = {
		menu: menu,
		dropdown: dropdown,
		startX: event.touches[0].clientX,
		startY: event.touches[0].clientY,
		startTime: Date.now(),
		delta: 0,
		dragging: false
	};
};

GrocySheet.DropdownMove = function (event)
{
	var state = GrocySheet.DropdownState;

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
			GrocySheet.DropdownState = null;
			return;
		}

		state.dragging = true;
		state.menu.classList.add("sheet-dragging");
	}

	if (event.cancelable)
	{
		event.preventDefault();
	}

	state.delta = Math.max(0, deltaY);
	GrocySheet.SetDropdownOffset(state.menu, state.delta + "px");

	var height = state.menu.offsetHeight || GrocySheet.VisibleHeight();
	GrocySheet.SetDropdownBackdropOpacity(Math.max(0, 1 - state.delta / height));
};

GrocySheet.DropdownEnd = function ()
{
	var state = GrocySheet.DropdownState;
	GrocySheet.DropdownState = null;

	if (!state || !state.dragging)
	{
		return;
	}

	var velocity = state.delta / Math.max(1, Date.now() - state.startTime);

	if (state.delta >= GrocySheet.DismissDistance || velocity >= GrocySheet.DismissVelocity)
	{
		// Slide the rest of the way out before Bootstrap removes the menu,
		// otherwise it would jump back up first
		state.menu.classList.remove("sheet-dragging");
		GrocySheet.SetDropdownOffset(state.menu, "100%");
		GrocySheet.SetDropdownBackdropOpacity(0);

		window.setTimeout(function ()
		{
			GrocySheet.CloseDropdown(state.dropdown);
		}, 160);

		return;
	}

	GrocySheet.DropdownSpringBack(state);
};

GrocySheet.DropdownCancel = function ()
{
	var state = GrocySheet.DropdownState;
	GrocySheet.DropdownState = null;

	if (state && state.dragging)
	{
		GrocySheet.DropdownSpringBack(state);
	}
};

GrocySheet.DropdownSpringBack = function (state)
{
	// Dropping the class re-enables the CSS transition, so it animates back
	state.menu.classList.remove("sheet-dragging");
	GrocySheet.SetDropdownOffset(state.menu, null);
	GrocySheet.SetDropdownBackdropOpacity(null);
};

// Bound to the menu itself, not to the document: a document level touchmove
// listener is passive by default in some browsers, and then the sheet could
// not keep the page from scrolling underneath it
GrocySheet.AttachDropdownDrag = function (menu, dropdown)
{
	if (menu.grocyDropdownDragAttached)
	{
		return;
	}

	menu.grocyDropdownDragAttached = true;

	menu.addEventListener("touchstart", function (e)
	{
		GrocySheet.DropdownStart(e, menu, dropdown);
	}, { passive: true });

	menu.addEventListener("touchmove", function (e)
	{
		GrocySheet.DropdownMove(e);
	}, { passive: false });

	menu.addEventListener("touchend", function ()
	{
		GrocySheet.DropdownEnd();
	}, { passive: true });

	menu.addEventListener("touchcancel", function ()
	{
		GrocySheet.DropdownCancel();
	}, { passive: true });
};

$(document).on("show.bs.dropdown", "td.dt-cell-actions .dropdown", function ()
{
	if (!GrocySheet.IsDropdownSheet() || document.querySelector(".dropdown-sheet-backdrop"))
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

	var menu = this.querySelector(".dropdown-menu");

	if (menu)
	{
		menu.style.setProperty("max-height", Math.round(GrocySheet.VisibleHeight() * GrocySheet.DropdownHeightRatio) + "px");
		GrocySheet.AttachDropdownDrag(menu, this);
	}
});

$(document).on("shown.bs.dropdown", "td.dt-cell-actions .dropdown", function ()
{
	var menu = this.querySelector(".dropdown-menu");

	// A menu that was scrolled down and closed again keeps its scroll
	// position, which looks exactly like a missing first entry
	if (menu)
	{
		menu.scrollTop = 0;
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

	var menu = this.querySelector(".dropdown-menu");

	if (menu)
	{
		GrocySheet.ResetDropdown(menu);
	}
});
