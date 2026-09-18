<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_menu_access('accounts');

$filters = [
    'category' => $_GET['category'] ?? '',
    'start_date' => $_GET['start_date'] ?? '',
    'end_date' => $_GET['end_date'] ?? '',
    'search' => trim((string) ($_GET['q'] ?? '')),
];

$totalExpenses = count_expenses($filters);
$pagination = paginate($totalExpenses, 20);
$expenses = list_expenses($filters, $pagination['perPage'], $pagination['offset']);
$categories = list_expense_categories();

$pageTitle = 'Expenses';
$activeMenu = 'accounts';
$breadcrumbs = [
    ['label' => 'Accounts', 'url' => url('accounts/index.php')],
    ['label' => 'Expenses', 'url' => null],
];

require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/sidebar.php';
require __DIR__ . '/../includes/navbar.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0">Expenses <span class="text-muted fs-6">(<?= e((string) $totalExpenses) ?>)</span></h1>
    <?php if (user_can(current_user(), 'accounts', 'add')): ?>
        <a href="<?= e(url('accounts/expense_form.php')) ?>" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i> Add Expense</a>
    <?php endif; ?>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" action="<?= e(url('accounts/expenses.php')) ?>" class="row g-2">
            <div class="col-12 col-md-4">
                <input type="text" name="q" class="form-control form-control-sm" placeholder="Search description or vendor" value="<?= e($filters['search']) ?>">
            </div>
            <div class="col-6 col-md-2">
                <select name="category" class="form-select form-select-sm">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= e($cat) ?>" <?= $filters['category'] === $cat ? 'selected' : '' ?>><?= e($cat) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-3 col-md-2"><input type="date" name="start_date" class="form-control form-control-sm" value="<?= e($filters['start_date']) ?>"></div>
            <div class="col-3 col-md-2"><input type="date" name="end_date" class="form-control form-control-sm" value="<?= e($filters['end_date']) ?>"></div>
            <div class="col-12 col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm flex-fill">Filter</button>
                <a href="<?= e(url('accounts/expenses.php')) ?>" class="btn btn-outline-secondary btn-sm">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover align-middle mb-3">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Category</th>
                        <th>Description</th>
                        <th>Vendor</th>
                        <th>Project</th>
                        <th class="text-end">Amount</th>
                        <th class="text-end">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($expenses)): ?>
                        <tr><td colspan="7" class="text-center text-muted py-4">No expenses found.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($expenses as $expense): ?>
                        <tr>
                            <td><?= e($expense['expense_date']) ?></td>
                            <td><?= e($expense['category']) ?></td>
                            <td><?= field_or($expense['description'] ?? null, 'Not provided') ?></td>
                            <td><?= field_or($expense['vendor'] ?? null, 'Not specified') ?></td>
                            <td><?= field_or($expense['project_title'] ?? null, 'Not linked') ?></td>
                            <td class="text-end">₹<?= e(number_format((float) $expense['amount'], 2)) ?></td>
                            <td class="text-end">
                                <?php if (user_can(current_user(), 'accounts', 'edit')): ?>
                                    <a href="<?= e(url('accounts/expense_form.php?id=' . $expense['id'])) ?>" class="btn btn-sm btn-outline-secondary">Edit</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= render_pagination($pagination['page'], $pagination['totalPages']) ?>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
