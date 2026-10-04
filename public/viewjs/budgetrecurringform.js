function BudgetRecurringType()
{
	return $("input[name='type']:checked").val() || "expense";
}

function BudgetRefreshRecurringForm(keepSelection)
{
	var type = BudgetRecurringType();
	var isTransfer = type === "transfer";
	var budgetId = $("#budget_id").val();

	$(".budget-transfer-only").toggleClass("d-none", !isTransfer);
	$(".budget-plain-only").toggleClass("d-none", isTransfer);
	$(".budget-label-plain").toggleClass("d-none", isTransfer);
	$(".budget-label-transfer").toggleClass("d-none", !isTransfer);

	// A transfer goes to any other budget - and needs that budget visible
	// even when there is only one to book from
	if (isTransfer)
	{
		$("#budget_id").closest(".form-group").removeClass("d-none");
	}

	var targetSelect = $("#target_budget_id");
	targetSelect.find("option").each(function ()
	{
		$(this).prop("disabled", $(this).val() === budgetId);
	});

	if (targetSelect.val() === budgetId || !targetSelect.val())
	{
		targetSelect.val(targetSelect.find("option:not(:disabled)").first().val());
	}

	var categories = BudgetForm.CategoriesByBudget[budgetId] || [];
	var targetCategories = BudgetForm.CategoriesByBudget[targetSelect.val()] || [];

	GrocyBudget.FillCategories("#category_id", categories, type === "income", keepSelection ? BudgetForm.SelectedCategoryId : undefined, null, __t("Other categories"));
	GrocyBudget.FillCategories("#target_category_id", targetCategories, true, keepSelection ? BudgetForm.SelectedTargetCategoryId : undefined, null, __t("Other categories"));
}

function BudgetRefreshCatchUpHint()
{
	var start = $("#start_date").val();
	$("#catch-up-hint").toggleClass("d-none", BudgetForm.Mode !== "create" || !start || start >= GrocyBudget.Today());
}

$("input[name='type'], #budget_id, #target_budget_id").on("change", function ()
{
	BudgetRefreshRecurringForm(false);
});

// The day of the month follows the start date until it is set on its own
var dayTouched = BudgetForm.Mode === "edit";
$("#day_of_month").on("input", function ()
{
	dayTouched = true;
});

$("#start_date").on("change", function ()
{
	var start = $(this).val();

	if (!dayTouched && start)
	{
		$("#day_of_month").val(Number(start.substring(8, 10)));
	}

	BudgetRefreshCatchUpHint();
});

$("#amount").on("input", function ()
{
	var amount = GrocyBudget.ParseAmount($(this).val());
	this.setCustomValidity(amount !== null && amount !== 0 ? "" : __t("Please enter an amount"));
	Grocy.FrontendHelpers.ValidateForm("budget-recurring-form");
});

$("#save-budget-recurring-button").on("click", function (e)
{
	e.preventDefault();

	$("#amount").trigger("input");
	if (!Grocy.FrontendHelpers.ValidateForm("budget-recurring-form", true))
	{
		return;
	}

	var type = BudgetRecurringType();
	var amount = Math.abs(GrocyBudget.ParseAmount($("#amount").val()));

	var data = {
		"name": $("#name").val(),
		"amount": type === "expense" ? -amount : amount,
		"budget_id": $("#budget_id").val(),
		"category_id": $("#category_id").val(),
		"target_budget_id": type === "transfer" ? $("#target_budget_id").val() : "",
		"target_category_id": type === "transfer" ? $("#target_category_id").val() : "",
		"payee": type === "transfer" ? "" : $("#payee").val(),
		"note": $("#note").val(),
		"interval_months": $("#interval_months").val(),
		"day_of_month": $("#day_of_month").val(),
		"start_date": $("#start_date").val(),
		"end_date": $("#end_date").val(),
		"active": $("#active").is(":checked")
	};

	Grocy.FrontendHelpers.BeginUiBusy("budget-recurring-form");

	var done = function ()
	{
		GrocyBudget.Done(U("/budget/recurring?budget=" + data.budget_id));
	};

	var failed = function (xhr)
	{
		Grocy.FrontendHelpers.EndUiBusy("budget-recurring-form");
		GrocyBudget.ShowError(xhr);
	};

	if (BudgetForm.Mode === "create")
	{
		Grocy.Api.Post("budget/recurring", data, done, failed);
	}
	else
	{
		Grocy.Api.Put("budget/recurring/" + BudgetForm.RecurringId, data, done, failed);
	}
});

$("#delete-budget-recurring-button").on("click", function ()
{
	GrocyBudget.ConfirmDelete(
		__t('Are you sure you want to delete the recurring transaction "%s"? What it has booked so far stays.', $(this).attr("data-name")),
		"budget/recurring/" + BudgetForm.RecurringId,
		BudgetForm.ReturnUrl
	);
});

$("#budget-recurring-form input").on("keydown", function (event)
{
	if (event.keyCode === 13) // Enter
	{
		event.preventDefault();
		$("#save-budget-recurring-button").click();
	}
});

BudgetRefreshRecurringForm(true);
BudgetRefreshCatchUpHint();

setTimeout(function ()
{
	if (BudgetForm.Mode === "create")
	{
		$("#name").focus();
	}
}, Grocy.FormFocusDelay);
