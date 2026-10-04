function BudgetTransactionType()
{
	return $("input[name='type']:checked").val() || "expense";
}

// Expenses pick from the expense categories. Income from the income ones - or
// from an expense category, which makes it a refund lowering what was spent.
function BudgetRefreshCategories(selectedId)
{
	var budgetId = $("#budget_id").val();
	var categories = BudgetForm.CategoriesByBudget[budgetId] || [];

	if (BudgetForm.IsTransfer)
	{
		GrocyBudget.FillCategories("#category_id", categories, null, selectedId);
		return;
	}

	var isIncome = BudgetTransactionType() === "income";
	GrocyBudget.FillCategories("#category_id", categories, isIncome, selectedId, null, isIncome ? __t("Refund of an expense") : __t("Other categories"));
	BudgetRefreshRefundHint();
}

function BudgetRefreshRefundHint()
{
	var isRefund = BudgetTransactionType() === "income" && $("#category_id option:selected").parent().is("optgroup");
	$("#refund-hint").toggleClass("d-none", BudgetTransactionType() !== "income" || ($("#category_id").val() !== "" && !isRefund));
}

function BudgetValidateAmount()
{
	var amount = GrocyBudget.ParseAmount($("#amount").val());
	var valid = amount !== null && amount !== 0;
	$("#amount")[0].setCustomValidity(valid ? "" : __t("Please enter an amount"));
	return valid;
}

$("#budget_id").on("change", function ()
{
	BudgetRefreshCategories();
});

$("input[name='type']").on("change", function ()
{
	BudgetRefreshCategories();
});

$("#category_id").on("change", BudgetRefreshRefundHint);

$("#amount").on("input", function ()
{
	BudgetValidateAmount();
	Grocy.FrontendHelpers.ValidateForm("budget-transaction-form");
});

$("#save-budget-transaction-button").on("click", function (e)
{
	e.preventDefault();

	BudgetValidateAmount();
	if (!Grocy.FrontendHelpers.ValidateForm("budget-transaction-form", true))
	{
		return;
	}

	var amount = Math.abs(GrocyBudget.ParseAmount($("#amount").val()));

	if (!BudgetForm.IsTransfer && BudgetTransactionType() === "expense")
	{
		amount = -amount;
	}

	var data = {
		"budget_id": $("#budget_id").val(),
		"category_id": $("#category_id").val(),
		"date": $("#date").val(),
		"amount": amount,
		"payee": $("#payee").val() || "",
		"note": $("#note").val()
	};

	Grocy.FrontendHelpers.BeginUiBusy("budget-transaction-form");

	var done = function ()
	{
		GrocyBudget.Done(U("/budget/transactions?budget=" + data.budget_id + "&month=" + data.date.substring(0, 7)));
	};

	var failed = function (xhr)
	{
		Grocy.FrontendHelpers.EndUiBusy("budget-transaction-form");
		GrocyBudget.ShowError(xhr);
	};

	if (BudgetForm.Mode === "create")
	{
		Grocy.Api.Post("budget/transactions", data, done, failed);
	}
	else
	{
		Grocy.Api.Put("budget/transactions/" + BudgetForm.TransactionId, data, done, failed);
	}
});

$("#delete-budget-transaction-button").on("click", function ()
{
	var name = $(this).attr("data-name");
	var question = BudgetForm.IsTransfer
		? __t('Are you sure you want to delete the transfer "%s"? Both sides of it are removed.', name)
		: __t('Are you sure you want to delete the transaction "%s"?', name);

	GrocyBudget.ConfirmDelete(question, "budget/transactions/" + BudgetForm.TransactionId, BudgetForm.ReturnUrl);
});

$("#budget-transaction-form input").on("keydown", function (event)
{
	if (event.keyCode === 13) // Enter
	{
		event.preventDefault();
		$("#save-budget-transaction-button").click();
	}
});

BudgetRefreshCategories(BudgetForm.SelectedCategoryId);

setTimeout(function ()
{
	if (BudgetForm.Mode === "create")
	{
		$("#amount").focus();
	}
}, Grocy.FormFocusDelay);
