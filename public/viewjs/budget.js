// Household budget overview (Grocy Modern)

// A plan is set from the month it is shown in on - the months before keep
// theirs, which is what the prompt says
$(document).on("click", ".budget-plan-button", function ()
{
	var button = $(this);
	var categoryId = button.data("category-id");

	bootbox.prompt({
		title: __t("Monthly plan for %s", button.data("category-name")),
		message: '<p class="text-muted small mb-2">' + __t("Applies from %s on, earlier months keep their plan.", BudgetPage.MonthLabel) + '</p>',
		inputType: "text",
		value: String(button.data("plan")).replace(".", (0.5).toLocaleString(GrocyBudget.Locale).charAt(1)),
		closeButton: false,
		buttons: {
			confirm: { label: __t("Save"), className: "btn-primary" },
			cancel: { label: __t("Cancel"), className: "btn-secondary" }
		},
		callback: function (value)
		{
			if (value === null)
			{
				return;
			}

			var amount = GrocyBudget.ParseAmount(value === "" ? "0" : value);

			if (amount === null || amount < 0)
			{
				toastr.error(__t("Please enter a valid amount"));
				return false;
			}

			Grocy.Api.Put("budget/categories/" + categoryId + "/plan", { month: BudgetPage.Month, amount: amount },
				function ()
				{
					window.location.reload();
				},
				function (xhr)
				{
					GrocyBudget.ShowError(xhr);
				}
			);
		}
	});

	// A number pad on phones
	window.setTimeout(function ()
	{
		$(".bootbox-input-text").attr("inputmode", "decimal").trigger("select");
	}, 50);
});

// The explanations behind the (i) buttons - a sheet on phones, a small dialog
// otherwise, so the tiles themselves only need to carry the numbers
$(document).on("click", ".budget-tile-info", function ()
{
	bootbox.dialog({
		message: $($(this).attr("data-info-template")).html(),
		className: "budget-info-dialog",
		size: "small",
		onEscape: true,
		backdrop: true,
		closeButton: false,
		buttons: {
			ok: { label: __t("OK"), className: "btn-primary" }
		}
	});
});
