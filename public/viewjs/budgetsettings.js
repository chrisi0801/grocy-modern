$(document).on("click", ".budget-delete-button", function (e)
{
	e.preventDefault();

	var button = $(e.currentTarget);
	GrocyBudget.ConfirmDelete(
		__t('Are you sure you want to delete the budget "%s"? All its transactions, plans, categories and recurring transactions are deleted with it.', button.attr("data-budget-name")),
		"budget/budgets/" + button.attr("data-budget-id"),
		U("/budget/settings")
	);
});

$(document).on("click", ".budget-category-delete-button", function (e)
{
	e.preventDefault();

	var button = $(e.currentTarget);
	GrocyBudget.ConfirmDelete(
		__t('Are you sure you want to delete the category "%s"? Its transactions stay, they are then without category.', button.attr("data-category-name")),
		"budget/categories/" + button.attr("data-category-id"),
		window.location.href
	);
});
