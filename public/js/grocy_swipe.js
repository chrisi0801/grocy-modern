// Swipe actions on the mobile cards.
//
// In card mode a row is a card, and the two actions needed several times a day
// become gestures:
//
//   swipe left  -> consume the quick consume amount
//   swipe right -> add to the shopping list
//
// Both simply trigger the controls that already exist in the row, so all of
// grocy's own logic (API call, toast with undo, row refresh, permissions)
// applies unchanged. A row without those controls does not react at all, which
// keeps this limited to the views that actually have them.

var GrocySwipe = {
	// Same breakpoint as the card mode in grocy_mobile.css
	Breakpoint: 768,
	// Movement needed before the action fires
	Threshold: 96,
	// The card does not follow the finger further than this
	MaxTravel: 140,
	// Ignore the first few pixels, and give up if the gesture is vertical
	Slop: 8
};

GrocySwipe.State = null;

GrocySwipe.IsCardMode = function ()
{
	return window.innerWidth < GrocySwipe.Breakpoint;
};

// The coloured area behind the card is a real element, not a pseudo element:
// generated content on a <tr> is not reliable across browsers, and this way it
// can carry a label as well
GrocySwipe.CreateIndicator = function (row, direction)
{
	var indicator = document.createElement("div");
	indicator.className = "swipe-indicator " + (direction < 0 ? "swipe-indicator-left" : "swipe-indicator-right");

	var icon = document.createElement("i");
	icon.className = direction < 0 ? "fa-solid fa-utensils" : "fa-solid fa-cart-plus";

	var label = document.createElement("span");
	// Short labels - the strip is only as wide as the swipe
	label.textContent = direction < 0 ? __t("Consume") : __t("Shopping list");

	indicator.appendChild(icon);
	indicator.appendChild(label);
	row.appendChild(indicator);

	return indicator;
};

GrocySwipe.RemoveIndicator = function (state)
{
	if (state.indicator && state.indicator.parentNode)
	{
		state.indicator.parentNode.removeChild(state.indicator);
	}

	state.indicator = null;
};

// The control a swipe in the given direction would trigger, or null
GrocySwipe.ActionFor = function (row, direction)
{
	var element = null;

	if (direction < 0)
	{
		// The quick consume button - not the "consume all" entry, which lives
		// in the overflow menu and carries the same class
		element = row.querySelector("td.dt-cell-actions a.btn.product-consume-button");
	}
	else
	{
		element = row.querySelector("td.dt-cell-actions .dropdown-menu a.permission-SHOPPINGLIST_ITEMS_ADD");
	}

	if (!element || element.classList.contains("disabled") || element.classList.contains("d-none"))
	{
		return null;
	}

	return element;
};

GrocySwipe.SetOffset = function (state, offset)
{
	var cells = state.row.children;

	for (var i = 0; i < cells.length; i++)
	{
		// Only the content moves: the action buttons stay in their corner, and
		// the indicator is anchored to the card edge
		if (cells[i].tagName !== "TD" || cells[i].classList.contains("dt-cell-actions"))
		{
			continue;
		}

		cells[i].style.transform = "translateX(" + offset + "px)";
	}

	if (!state.indicator)
	{
		return;
	}

	// Exactly as wide as the strip the content uncovered, so it never sits on
	// top of text that is still visible
	state.indicator.style.width = Math.abs(offset) + "px";

	// Past the threshold the action would fire - say so
	state.indicator.classList.toggle("is-ready", Math.abs(offset) >= GrocySwipe.Threshold);
};

GrocySwipe.Reset = function (state, animate)
{
	var row = state.row;

	GrocySwipe.SetOffset(state, 0);

	if (animate)
	{
		row.classList.add("swipe-returning");
		window.setTimeout(function ()
		{
			row.classList.remove("swipe-returning");
			row.classList.remove("swiping");
			GrocySwipe.RemoveIndicator(state);
		}, 200);
	}
	else
	{
		row.classList.remove("swiping");
		GrocySwipe.RemoveIndicator(state);
	}
};

GrocySwipe.Start = function (e)
{
	GrocySwipe.State = null;

	if (!GrocySwipe.IsCardMode() || e.touches.length !== 1)
	{
		return;
	}

	var target = e.target;

	if (!target || !target.closest)
	{
		return;
	}

	// Anything interactive keeps its own behaviour
	if (target.closest("a, button, input, select, textarea, .dropdown"))
	{
		return;
	}

	var row = target.closest("table.dt-cards > tbody > tr");

	if (!row || row.classList.contains("dtrg-group"))
	{
		return;
	}

	// Nothing to trigger in either direction -> not a swipeable row
	if (!GrocySwipe.ActionFor(row, -1) && !GrocySwipe.ActionFor(row, 1))
	{
		return;
	}

	GrocySwipe.State = {
		row: row,
		startX: e.touches[0].clientX,
		startY: e.touches[0].clientY,
		offset: 0,
		dragging: false,
		indicator: null
	};
};

GrocySwipe.Move = function (e)
{
	var state = GrocySwipe.State;

	if (!state || e.touches.length !== 1)
	{
		return;
	}

	var deltaX = e.touches[0].clientX - state.startX;
	var deltaY = e.touches[0].clientY - state.startY;

	if (!state.dragging)
	{
		if (Math.abs(deltaX) < GrocySwipe.Slop)
		{
			return;
		}

		// Vertical intent: leave the gesture to the page
		if (Math.abs(deltaY) > Math.abs(deltaX))
		{
			GrocySwipe.State = null;
			return;
		}

		// No action in this direction -> don't pretend there is one
		if (!GrocySwipe.ActionFor(state.row, deltaX < 0 ? -1 : 1))
		{
			GrocySwipe.State = null;
			return;
		}

		state.dragging = true;
		// Only while swiping, so the row action menu can still escape the card
		state.row.classList.add("swiping");
		state.indicator = GrocySwipe.CreateIndicator(state.row, deltaX < 0 ? -1 : 1);
	}

	if (e.cancelable)
	{
		e.preventDefault();
	}

	// Resist beyond the threshold instead of following one to one
	var travel = Math.abs(deltaX);

	if (travel > GrocySwipe.Threshold)
	{
		travel = GrocySwipe.Threshold + (travel - GrocySwipe.Threshold) * 0.35;
	}

	state.offset = Math.min(travel, GrocySwipe.MaxTravel) * (deltaX < 0 ? -1 : 1);
	GrocySwipe.SetOffset(state, state.offset);
};

GrocySwipe.End = function ()
{
	var state = GrocySwipe.State;
	GrocySwipe.State = null;

	if (!state || !state.dragging)
	{
		return;
	}

	var action = Math.abs(state.offset) >= GrocySwipe.Threshold
		? GrocySwipe.ActionFor(state.row, state.offset < 0 ? -1 : 1)
		: null;

	GrocySwipe.Reset(state, true);

	if (action)
	{
		// Let the card settle back before grocy's own handler redraws the row
		window.setTimeout(function ()
		{
			$(action).trigger("click");
		}, 120);
	}
};

GrocySwipe.Cancel = function ()
{
	var state = GrocySwipe.State;
	GrocySwipe.State = null;

	if (state && state.dragging)
	{
		GrocySwipe.Reset(state, true);
	}
};

document.addEventListener("touchstart", GrocySwipe.Start, { passive: true });
document.addEventListener("touchmove", GrocySwipe.Move, { passive: false });
document.addEventListener("touchend", GrocySwipe.End, { passive: true });
document.addEventListener("touchcancel", GrocySwipe.Cancel, { passive: true });
