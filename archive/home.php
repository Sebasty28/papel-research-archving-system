<?php
require_once __DIR__.'/../config/core.php';
require_once __DIR__.'/../config/gdrive_config.php';
start_session_once();
$u = current_user();

// Public Page - No Login Enforcement

// Check guest expiry
if (isset($_SESSION['guest_login']) && isset($_SESSION['guest_expire'])) {
    if (time()> $_SESSION['guest_expire']) {
        header('Location: ../logout.php?from=archive&expired=1');
        exit;
    }
}

$can_view = $u || isset($_SESSION['guest_login']);

$conn = db();
$search = trim($_GET['q'] ?? '');
$filter_year = (int)($_GET['year'] ?? 0);
$filter_type = trim($_GET['type'] ?? '');

$sql = "SELECT paper_id, title, year, abstract, keywords, file_path, gdrive_file_id, upload_date, paper_type FROM research_papers WHERE current_status IN ('approved', 'pending_admin_l2', 'pending_head_academic', 'pending_super_admin')";
$params = [];
$types = '';

if ($search) {
    $sql .= " AND (title LIKE ? OR keywords LIKE ? OR abstract LIKE ?)";
    $term = "%$search%";
    $params[] = $term; $params[] = $term; $params[] = $term;
    $types .= 'sss';
}

if ($filter_year> 0) {
    $sql .= " AND year = ?";
    $params[] = $filter_year;
    $types .= 'i';
}

if ($filter_type) {
    $sql .= " AND paper_type = ?";
    $params[] = $filter_type;
    $types .= 's';
}

// Build and execute count query before adding LIMIT
$count_sql = preg_replace('/SELECT .*? FROM/', 'SELECT COUNT(*) as total FROM', $sql, 1);
$count_stmt = $conn->prepare($count_sql);
if (!empty($params)) {
    $count_stmt->bind_param($types, ...$params);
}
$count_stmt->execute();
$total_papers = $count_stmt->get_result()->fetch_assoc()['total'] ?? 0;
$count_stmt->close();

$sql .= " ORDER BY year DESC, upload_date DESC LIMIT 50";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

// Get available years for filter
$years_res = $conn->query("SELECT DISTINCT year FROM research_papers WHERE current_status IN ('approved', 'pending_admin_l2', 'pending_head_academic', 'pending_super_admin') ORDER BY year DESC");

// Get available types for filter
$types_res = $conn->query("SELECT DISTINCT paper_type FROM research_papers WHERE current_status IN ('approved', 'pending_admin_l2', 'pending_head_academic', 'pending_super_admin') ORDER BY paper_type ASC");
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Public Archive · <?= e(APP_NAME) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Crimson+Pro:wght@400;600;700&family=IBM+Plex+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
    :root {
        --paper-bg: #fcf8f7;
        --paper-white: #ffffff;
        --ink-dark: #1a1a1a;
        --ink-medium: #4a4a4a;
        --ink-light: #757575;
        --accent-blue: #810403;
        --accent-blue-light: #a01c1b;
        --accent-yellow: #dca92c;
        --accent-yellow-dark: #b8860b;
        --accent-cream: #fede0e;
        --border-light: #e8e6e3;
        --shadow-sm: 0 1px 3px rgba(0,0,0,0.04);
        --shadow-md: 0 4px 12px rgba(0,0,0,0.06);
        --shadow-lg: 0 8px 24px rgba(0,0,0,0.08);
        --shadow-xl: 0 16px 48px rgba(0,0,0,0.1);
    }
    
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }
    
    body {
        font-family: 'IBM Plex Sans', -apple-system, BlinkMacSystemFont, sans-serif;
        background: linear-gradient(135deg, var(--paper-bg) 0%, #faf9f7 100%);
        color: var(--ink-dark);
        line-height: 1.6;
        overflow-x: hidden;
    }
    
    /* Decorative Background Elements */
    body::before {
        content: '';
        position: fixed;
        top: 0;
        right: 0;
        width: 600px;
        height: 600px;
        background: radial-gradient(circle, rgba(129, 4, 3, 0.05) 0%, transparent 70%);
        pointer-events: none;
        z-index: 0;
    }
    
    body::after {
        content: '';
        position: fixed;
        bottom: 0;
        left: 0;
        width: 500px;
        height: 500px;
        background: radial-gradient(circle, rgba(220, 169, 44, 0.04) 0%, transparent 70%);
        pointer-events: none;
        z-index: 0;
    }
    
    /* Navbar */
    .navbar {
        background: var(--paper-white);
        box-shadow: 0 2px 12px rgba(0,0,0,0.04);
        padding: 1.25rem 0;
        position: sticky;
        top: 0;
        z-index: 1000;
        border-bottom: 1px solid var(--border-light);
        backdrop-filter: blur(10px);
        animation: slideDown 0.6s ease-out;
    }
    
    @keyframes slideDown {
        from {
            transform: translateY(-100%);
            opacity: 0;
        }
        to {
            transform: translateY(0);
            opacity: 1;
        }
    }
    
    .navbar-brand {
        font-family: 'Crimson Pro', Georgia, serif;
        font-weight: 700;
        font-size: 1.75rem;
        color: var(--ink-dark) !important;
        display: flex;
        align-items: center;
        gap: 0.75rem;
        transition: all 0.3s ease;
    }
    
    .navbar-brand:hover {
        color: var(--accent-blue) !important;
    }
    
    .navbar-brand img {
        transition: transform 0.3s ease;
    }
    
    .navbar-brand:hover img {
        transform: rotate(5deg) scale(1.05);
    }
    
    .archive-badge {
        background: linear-gradient(135deg, var(--accent-blue), var(--accent-blue-light));
        color: white;
        padding: 0.25rem 0.75rem;
        border-radius: 20px;
        font-size: 0.75rem;
        font-weight: 600;
        letter-spacing: 0.5px;
        text-transform: uppercase;
        margin-left: 0.5rem;
    }
    
    .navbar-text {
        color: var(--ink-medium) !important;
        font-weight: 500;
    }
    
    .btn-outline-custom {
        border: 2px solid var(--accent-blue);
        color: var(--accent-blue);
        font-weight: 600;
        padding: 0.5rem 1.5rem;
        border-radius: 8px;
        transition: all 0.3s ease;
    }
    
    .btn-outline-custom:hover {
        background: var(--accent-blue);
        color: white;
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(129, 4, 3, 0.3);
    }
    
    /* Container */
    .main-container {
        max-width: 1400px;
        margin: 0 auto;
        padding: 3rem 2rem;
        position: relative;
        z-index: 1;
        animation: fadeInUp 0.8s ease-out;
    }
    
    @keyframes fadeInUp {
        from {
            opacity: 0;
            transform: translateY(30px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    
    /* Filter Sidebar */
    .filter-sidebar {
        position: sticky;
        top: 100px;
        animation: slideInLeft 0.6s ease-out;
    }
    
    @keyframes slideInLeft {
        from {
            opacity: 0;
            transform: translateX(-30px);
        }
        to {
            opacity: 1;
            transform: translateX(0);
        }
    }
    
    .filter-card {
        background: var(--paper-white);
        border: 1px solid var(--border-light);
        border-radius: 16px;
        box-shadow: var(--shadow-md);
        overflow: hidden;
        transition: all 0.3s ease;
    }
    
    .filter-card:hover {
        box-shadow: var(--shadow-lg);
    }
    
    .filter-header {
        background: linear-gradient(135deg, #fff8e1 0%, var(--paper-white) 100%);
        border-bottom: 2px solid var(--accent-blue);
        padding: 1.25rem 1.5rem;
        font-family: 'Crimson Pro', Georgia, serif;
        font-weight: 700;
        font-size: 1.125rem;
        color: var(--ink-dark);
        position: relative;
    }
    
    .filter-header::after {
        content: '';
        position: absolute;
        bottom: -2px;
        left: 0;
        width: 60px;
        height: 2px;
        background: var(--accent-yellow);
    }
    
    .filter-body {
        padding: 1.5rem;
    }
    
    .filter-section {
        margin-bottom: 1.75rem;
    }
    
    .filter-label {
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 1px;
        font-weight: 700;
        color: var(--accent-blue);
        margin-bottom: 0.75rem;
        display: block;
    }
    
    .form-check {
        padding-left: 1.75rem;
        margin-bottom: 0.75rem;
    }
    
    .form-check-input {
        width: 1.125rem;
        height: 1.125rem;
        border: 2px solid var(--border-light);
        margin-top: 0.125rem;
    }
    
    .form-check-input:checked {
        background-color: var(--accent-blue);
        border-color: var(--accent-blue);
    }
    
    .form-check-label {
        font-size: 0.9375rem;
        color: var(--ink-medium);
        cursor: pointer;
        transition: color 0.2s ease;
    }
    
    .form-check-label:hover {
        color: var(--accent-blue);
    }
    
    .form-select {
        border: 2px solid var(--border-light);
        border-radius: 10px;
        padding: 0.625rem 0.875rem;
        font-size: 0.9375rem;
        color: var(--ink-medium);
        transition: all 0.3s ease;
    }
    
    .form-select:focus {
        border-color: var(--accent-blue);
        box-shadow: 0 0 0 3px rgba(129, 4, 3, 0.1);
    }
    
    .btn-clear {
        background: transparent;
        border: 2px solid var(--border-light);
        color: var(--ink-medium);
        font-weight: 600;
        padding: 0.625rem;
        border-radius: 10px;
        transition: all 0.3s ease;
        width: 100%;
    }
    
    .btn-clear:hover {
        border-color: var(--accent-blue);
        color: var(--accent-blue);
        background: rgba(129, 4, 3, 0.02);
    }
    
    /* Search Bar */
    .search-wrapper {
        margin-bottom: 2rem;
        animation: fadeIn 0.8s ease-out 0.2s both;
    }
    
    @keyframes fadeIn {
        from { opacity: 0; }
        to { opacity: 1; }
    }
    
    .search-container {
        display: flex;
        gap: 0.75rem;
        background: var(--paper-white);
        padding: 0.5rem;
        border-radius: 16px;
        border: 2px solid var(--border-light);
        box-shadow: var(--shadow-md);
        transition: all 0.3s ease;
    }
    
    .search-container:focus-within {
        border-color: var(--accent-blue);
        box-shadow: 0 8px 24px rgba(129, 4, 3, 0.15);
        transform: translateY(-2px);
    }
    
    .search-input {
        flex: 1;
        border: none;
        padding: 0.875rem 1rem;
        font-size: 1rem;
        color: var(--ink-dark);
        background: transparent;
        outline: none;
    }
    
    .search-input::placeholder {
        color: var(--ink-light);
    }
    
    .btn-search {
        background: linear-gradient(135deg, var(--accent-blue), var(--accent-blue-light));
        color: white;
        border: none;
        padding: 0.875rem 2rem;
        border-radius: 12px;
        font-weight: 600;
        transition: all 0.3s ease;
        box-shadow: 0 4px 12px rgba(129, 4, 3, 0.25);
    }
    
    .btn-search:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 20px rgba(129, 4, 3, 0.35);
    }
    
    /* Paper Cards */
    .papers-grid {
        display: grid;
        gap: 1.5rem;
    }
    
    .paper-card {
        background: var(--paper-white);
        border: 1px solid var(--border-light);
        border-radius: 16px;
        padding: 2rem;
        box-shadow: var(--shadow-sm);
        transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        position: relative;
        overflow: hidden;
        animation: cardFadeIn 0.5s ease-out both;
    }
    
    .paper-card:nth-child(1) { animation-delay: 0.1s; }
    .paper-card:nth-child(2) { animation-delay: 0.15s; }
    .paper-card:nth-child(3) { animation-delay: 0.2s; }
    .paper-card:nth-child(4) { animation-delay: 0.25s; }
    .paper-card:nth-child(5) { animation-delay: 0.3s; }
    
    @keyframes cardFadeIn {
        from {
            opacity: 0;
            transform: translateY(20px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    
    .paper-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 4px;
        height: 0;
        background: linear-gradient(180deg, var(--accent-blue), var(--accent-yellow));
        transition: height 0.4s ease;
    }
    
    .paper-card:hover::before {
        height: 100%;
    }
    
    .paper-card:hover {
        transform: translateX(4px);
        box-shadow: var(--shadow-xl);
        border-color: rgba(129, 4, 3, 0.2);
    }
    
    .paper-title {
        font-family: 'Crimson Pro', Georgia, serif;
        font-size: 1.5rem;
        font-weight: 700;
        line-height: 1.4;
        margin-bottom: 1rem;
        color: var(--ink-dark);
    }
    
    .paper-title a {
        color: inherit;
        text-decoration: none;
        transition: color 0.3s ease;
        display: inline-block;
    }
    
    .paper-title a:hover {
        color: var(--accent-blue);
    }
    
    .paper-meta {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        margin-bottom: 1rem;
        flex-wrap: wrap;
    }
    
    .badge-type {
        background: linear-gradient(135deg, var(--accent-blue), var(--accent-blue-light));
        color: white;
        padding: 0.375rem 0.875rem;
        border-radius: 20px;
        font-size: 0.75rem;
        font-weight: 600;
        letter-spacing: 0.5px;
        text-transform: uppercase;
    }
    
    .paper-year {
        color: var(--ink-medium);
        font-weight: 600;
        font-size: 0.9375rem;
        display: flex;
        align-items: center;
        gap: 0.375rem;
    }
    
    .paper-year::before {
        content: '📅';
        font-size: 1rem;
    }
    
    .paper-abstract {
        color: var(--ink-medium);
        font-size: 0.9375rem;
        line-height: 1.7;
        margin-bottom: 1rem;
        display: -webkit-box;
        -webkit-line-clamp: 3;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }
    
    .keywords-wrapper {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
        margin-bottom: 1.25rem;
    }
    
    .keyword-badge {
        background: #fff8e1;
        color: var(--accent-blue);
        padding: 0.375rem 0.75rem;
        border-radius: 8px;
        font-size: 0.8125rem;
        font-weight: 500;
        border: 1px solid rgba(220, 169, 44, 0.3);
        transition: all 0.2s ease;
    }
    
    .keyword-badge:hover {
        background: var(--accent-yellow);
        color: var(--ink-dark);
        transform: translateY(-2px);
        border-color: var(--accent-yellow-dark);
    }
    
    .btn-view-details {
        background: transparent;
        color: var(--accent-blue);
        border: 2px solid var(--accent-blue);
        padding: 0.625rem 1.5rem;
        border-radius: 10px;
        font-weight: 600;
        font-size: 0.9375rem;
        transition: all 0.3s ease;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
    }
    
    .btn-view-details::after {
        content: '→';
        transition: transform 0.3s ease;
    }
    
    .btn-view-details:hover {
        background: var(--accent-blue);
        color: white;
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(129, 4, 3, 0.3);
    }
    
    .btn-view-details:hover::after {
        transform: translateX(4px);
    }
    
    /* Empty State */
    .empty-state {
        text-align: center;
        padding: 5rem 2rem;
        animation: fadeIn 0.8s ease-out;
    }
    
    .empty-icon {
        font-size: 4rem;
        margin-bottom: 1.5rem;
        opacity: 0.3;
    }
    
    .empty-message {
        font-family: 'Crimson Pro', Georgia, serif;
        font-size: 1.5rem;
        color: var(--ink-medium);
        font-weight: 600;
    }
    
    /* Footer */
    .footer {
        background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%);
        color: white;
        margin-top: 5rem;
        position: relative;
        overflow: hidden;
    }
    
    .footer::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 2px;
        background: linear-gradient(90deg, var(--accent-blue), var(--accent-yellow), var(--accent-blue));
    }
    
    .footer-content {
        max-width: 1400px;
        margin: 0 auto;
        padding: 3rem 2rem;
        display: grid;
        grid-template-columns: 1.5fr 1fr 1fr;
        gap: 3rem;
    }
    
    .footer-logo {
        font-family: 'Crimson Pro', Georgia, serif;
        display: flex;
        align-items: center;
        gap: 1rem;
        font-size: 2rem;
        font-weight: 700;
        margin-bottom: 1rem;
    }
    
    .footer-logo-icon {
        width: 50px;
        height: 50px;
        background: linear-gradient(135deg, var(--accent-blue), var(--accent-yellow));
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 900;
        font-size: 1.5rem;
    }
    
    .footer-description {
        font-size: 0.9375rem;
        line-height: 1.7;
        color: rgba(255, 255, 255, 0.7);
    }
    
    .footer-title {
        font-family: 'Crimson Pro', Georgia, serif;
        font-size: 1.125rem;
        font-weight: 700;
        margin-bottom: 1rem;
    }
    
    .footer-link {
        display: block;
        color: rgba(255, 255, 255, 0.7);
        text-decoration: none;
        font-size: 0.9375rem;
        margin-bottom: 0.75rem;
        transition: all 0.3s ease;
        position: relative;
        padding-left: 0;
    }
    
    .footer-link:hover {
        color: white;
        padding-left: 8px;
    }
    
    .footer-link::before {
        content: '→';
        position: absolute;
        left: 0;
        opacity: 0;
        transition: opacity 0.3s ease;
    }
    
    .footer-link:hover::before {
        opacity: 1;
    }
    
    .footer-copyright {
        background: rgba(0, 0, 0, 0.3);
        padding: 1.25rem;
        text-align: center;
        font-size: 0.875rem;
        color: rgba(255, 255, 255, 0.6);
        border-top: 1px solid rgba(255, 255, 255, 0.1);
    }
    
    /* Responsive */
    @media (max-width: 1024px) {
        .footer-content {
            grid-template-columns: 1fr 1fr;
        }
    }
    
    @media (max-width: 768px) {
        .main-container {
            padding: 2rem 1rem;
        }
        
        .filter-sidebar {
            position: static;
            margin-bottom: 2rem;
        }
        
        .footer-content {
            grid-template-columns: 1fr;
            gap: 2rem;
        }
        
        .search-container {
            flex-direction: column;
        }
        
        .btn-search {
            width: 100%;
        }
        
        .paper-card {
            padding: 1.5rem;
        }
        
        .paper-title {
            font-size: 1.25rem;
        }
    }
    
    /* Scrollbar Styling */
    ::-webkit-scrollbar {
        width: 12px;
    }
    
    ::-webkit-scrollbar-track {
        background: var(--accent-cream);
    }
    
    ::-webkit-scrollbar-thumb {
        background: linear-gradient(180deg, var(--accent-blue), var(--accent-yellow));
        border-radius: 6px;
    }
    
    ::-webkit-scrollbar-thumb:hover {
        background: var(--accent-blue);
    }
</style>
</head>
<body>
<nav class="navbar">
    <div class="container">
        <a class="navbar-brand" href="home.php">
            <img src="../assests/images/logo.png" alt="Logo" height="36">
            <?= e(APP_NAME) ?>
            <span class="archive-badge">Archive</span>
        </a>
        <div class="d-flex align-items-center gap-3">
            <?php if ($u): ?>
                <span class="navbar-text">
                    <?= isset($_SESSION['guest_login']) ? '👤 Guest Session' : '👤 ' . e($u['full_name']) ?>
                </span>
                <a href="../logout.php?from=archive" class="btn btn-outline-custom">Exit Archive</a>
            <?php else: ?>
                <a href="login.php" class="btn btn-outline-custom">Login</a>
            <?php endif; ?>
        </div>
    </div>
</nav>

<div class="main-container">
    <div class="row">
        <!-- Filter Sidebar -->
        <div class="col-lg-3 col-md-4 mb-4">
            <div class="filter-sidebar">
                <div class="filter-card">
                    <div class="filter-header">
                        📚 Filter Archives
                    </div>
                    <div class="filter-body">
                        <form id="filterForm" action="" method="get">
                            <?php if($search): ?><input type="hidden" name="q" value="<?= e($search) ?>"><?php endif; ?>
                            
                            <div class="filter-section">
                                <label class="filter-label">Paper Type</label>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="type" id="type_all" value="" <?= $filter_type===''?'checked':'' ?>>
                                    <label class="form-check-label" for="type_all">All Types</label>
                                </div>
                                <?php while($t = $types_res->fetch_assoc()): ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="type" id="type_<?= e($t['paper_type']) ?>" value="<?= e($t['paper_type']) ?>" <?= $filter_type===$t['paper_type']?'checked':'' ?>>
                                    <label class="form-check-label" for="type_<?= e($t['paper_type']) ?>">
                                        <?= e(ucfirst($t['paper_type'])) ?>
                                    </label>
                                </div>
                                <?php endwhile; ?>
                            </div>
                            
                            <div class="filter-section">
                                <label class="filter-label">Year</label>
                                <select name="year" class="form-select">
                                    <option value="">All Years</option>
                                    <?php while($y = $years_res->fetch_assoc()): ?>
                                    <option value="<?= $y['year'] ?>" <?= $filter_year==$y['year']?'selected':'' ?>>
                                        <?= $y['year'] ?>
                                    </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                            
                            <a href="home.php" class="btn-clear">Clear All Filters</a>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Main Content -->
        <div class="col-lg-9 col-md-8">
            <!-- Search Bar -->
            <div class="search-wrapper">
                <form action="" method="get">
                    <?php if($filter_year): ?><input type="hidden" name="year" value="<?= $filter_year ?>"><?php endif; ?>
                    <?php if($filter_type): ?><input type="hidden" name="type" value="<?= e($filter_type) ?>"><?php endif; ?>
                    <div class="search-container">
                        <input class="search-input" type="search" name="q" placeholder="Search by title, keywords, or abstract..." value="<?= e($search) ?>">
                        <button class="btn-search" type="submit">🔍 Search</button>
                    </div>
                </form>
                <div class="mt-2 text-muted small">
                    Showing <strong><?= $result->num_rows ?></strong> of <strong><?= number_format($total_papers) ?></strong> papers found.
                </div>
            </div>
            
            <!-- Papers Grid -->
            <div class="papers-grid">
                <?php while($r = $result->fetch_assoc()): ?>
                <div class="paper-card">
                    <h2 class="paper-title">
                        <a href="../view_paper.php?id=<?= $r['paper_id'] ?>">
                            <?= e($r['title']) ?>
                        </a>
                    </h2>
                    
                    <div class="paper-meta">
                        <span class="badge-type"><?= e(ucfirst($r['paper_type'] ?? 'Research')) ?></span>
                        <span class="paper-year"><?= e($r['year']) ?></span>
                    </div>
                    
                    <p class="paper-abstract"><?= e($r['abstract']) ?></p>
                    
                    <?php if($r['keywords'] && $u): // Only show keywords if logged in ?>
                    <div class="keywords-wrapper">
                        <?php foreach(explode(',', $r['keywords']) as $k): ?>
                        <span class="keyword-badge"><?= e(trim($k)) ?></span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    
                    <a href="../view_paper.php?id=<?= $r['paper_id'] ?>" class="btn-view-details">
                        View Details
                    </a>
                </div>
                <?php endwhile; ?>
                
                <?php if($result->num_rows === 0): ?>
                <div class="empty-state">
                    <div class="empty-icon">📭</div>
                    <p class="empty-message">No papers found matching your criteria.</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<footer class="footer">
    <div class="footer-content">
        <div>
            <div class="footer-logo">
                <div class="footer-logo-icon">P</div>
                PAPEL
            </div>
            <p class="footer-description">
                A student research library for submitting, tracking, and managing academic papers with excellence and integrity.
            </p>
        </div>
        <div>
            <div class="footer-title">Platform</div>
            <a href="../pages/about_us.php" class="footer-link">About</a>
            <a href="../pages/terms_and_conditions.php" class="footer-link">Terms & Conditions</a>
            <a href="../pages/privacy.php" class="footer-link">Privacy</a>
        </div>
        <div>
            <div class="footer-title">Support</div>
            <a href="../pages/help_center.php" class="footer-link">Help Center</a>
            <a href="../pages/contact_support.php" class="footer-link">Contact Support</a>
        </div>
    </div>
    <div class="footer-copyright">
        © 2026 Papel. All rights reserved. • Empowering Academic Research
    </div>
</footer>

<?php include __DIR__.'/../includes/accessibility.php'; ?>
<script nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
document.addEventListener('contextmenu', function(event) {
    event.preventDefault();
});

var filterForm = document.getElementById('filterForm');
if (filterForm) {
    filterForm.addEventListener('change', function(e) {
        if (e.target.matches('input[type="radio"]') || e.target.matches('select')) {
            filterForm.submit();
        }
    });
}
</script>
</body>
</html>