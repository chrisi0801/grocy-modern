// Compact toasts.
//
// Grocy's toasts carry a full sentence plus an "Undo" button on a second line,
// which on a phone covers a good part of the screen for 20 seconds. Here they
// are collapsed to a single line; tapping one expands it to the full text
// including the button.
//
// Loaded after grocy.js, which sets the toastr defaults.

// Tapping must expand instead of dismissing - the close button and the buttons
// inside the toast keep working
toastr.options.tapToDismiss = false;

var GrocyToast = {};

// A toast is only worth expanding if it actually has something hidden
GrocyToast.MarkExpandable = function (toast)
{
	// Note: grocy configures `toastClass: 'alert'`, so a toast does *not*
	// carry a "toast" class - identify it by its message element instead
	if (!toast || typeof toast.querySelector !== "function")
	{
		return;
	}

	var message = toast.querySelector(".toast-message");

	if (!message)
	{
		return;
	}

	if (message.querySelector("a, button, br"))
	{
		toast.classList.add("toast-has-details");
	}
};

// toastr creates its container lazily and appends toasts to it over time
GrocyToast.Observe = function ()
{
	if (typeof MutationObserver === "undefined")
	{
		return;
	}

	new MutationObserver(function (mutations)
	{
		mutations.forEach(function (mutation)
		{
			Array.prototype.forEach.call(mutation.addedNodes, function (node)
			{
				if (node.nodeType !== 1)
				{
					return;
				}

				if (node.id === "toast-container")
				{
					Array.prototype.forEach.call(node.children, GrocyToast.MarkExpandable);
				}
				else
				{
					GrocyToast.MarkExpandable(node);
				}
			});
		});
	}).observe(document.body, { childList: true, subtree: true });
};

$(document).on("click", "#toast-container > div", function (e)
{
	// Let the close button and anything actionable inside do their job
	if ($(e.target).closest(".toast-close-button, a, button").length)
	{
		return;
	}

	// Nothing to reveal: keep the familiar "tap to dismiss" behaviour
	if (!$(this).hasClass("toast-has-details"))
	{
		toastr.clear($(this));
		return;
	}

	$(this).toggleClass("toast-expanded");
});

$(function ()
{
	GrocyToast.Observe();
});
