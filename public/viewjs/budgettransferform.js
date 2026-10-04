// The category of the same name, when the budget has one - pocket money leaves
// the household as "Pocket money" and arrives as "Pocket money"
function BudgetCategoryNamed(categories, name, isIncome)
{
	var match = (categories || []).find(function (category)
	{
		return category.name === name && Boolean(Number(category.is_income)) === isIncome;
	});

	return match ? match.id : "";
}

function BudgetRefreshTransferCategories()
{
	var fromId = $("#from_budget_id").val();
	var toSelect = $("#to_budget_id");

	// The target is any other budget
	toSelect.find("option").each(function ()
	{
		$(this).prop("disabled", $(this).val() === fromId);
	});

	if (toSelect.val() === fromId || !toSelect.val())
	{
		toSelect.val(toSelect.find("option:not(:disabled)").first().val());
	}

	var fromCategories = BudgetForm.CategoriesByBudget[fromId] || [];
	var toCategories = BudgetForm.CategoriesByBudget[toSelect.val()] || [];

	GrocyBudget.FillCategories("#from_category_id", fromCategories, false, BudgetCategoryNamed(fromCategories, BudgetForm.PocketMoneyName, false), null, __t("Other categories"));
	GrocyBudget.FillCategories("#to_category_id", toCategories, true, BudgetCategoryNamed(toCategories, BudgetForm.PocketMoneyName, true), null, __t("Other categories"));
}

$("#from_budget_id, #to_budget_id").on("change", BudgetRefreshTransferCategories);

$("#amount").on("input", function ()
{
	var amount = GrocyBudget.ParseAmount($(this).val());
	this.setCustomValidity(amount !== null && amount !== 0 ? "" : __t("Please enter an amount"));
	Grocy.FrontendHelpers.ValidateForm("budget-transfer-form");
});

$("#save-budget-transfer-button").on("click", function (e)
{
	e.preventDefault();

	$("#amount").trigger("input");
	if (!Grocy.FrontendHelpers.ValidateForm("budget-transfer-form", true))
	{
		return;
	}

	var data = {
		"from_budget_id": $("#from_budget_id").val(),
		"to_budget_id": $("#to_budget_id").val(),
		"from_category_id": $("#from_category_id").val(),
		"to_category_id": $("#to_category_id").val(),
		"amount": Math.abs(GrocyBudget.ParseAmount($("#amount").val())),
		"date": $("#date").val(),
		"note": $("#note").val()
	};

	Grocy.FrontendHelpers.BeginUiBusy("budget-transfer-form");

	Grocy.Api.Post("budget/transfers", data,
		function ()
		{
			GrocyBudget.Done(U("/budget/transactions?budget=" + data.from_budget_id + "&month=" + data.date.substring(0, 7)));
		},
		function (xhr)
		{
			Grocy.FrontendHelpers.EndUiBusy("budget-transfer-form");
			GrocyBudget.ShowError(xhr);
		}
	);
});

$("#budget-transfer-form input").on("keydown", function (event)
{
	if (event.keyCode === 13) // Enter
	{
		event.preventDefault();
		$("#save-budget-transfer-button").click();
	}
});

BudgetRefreshTransferCategories();

setTimeout(function ()
{
	$("#amount").focus();
}, Grocy.FormFocusDelay);
