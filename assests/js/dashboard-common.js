"use strict";

let chartsInitialized = false;
let modalChartInstance = null;
const notificationHandlerPath = '../../notifications/notifications_handler.php';

function switchTab(section, tabElement) {
  const sections = document.querySelectorAll('[id$="Section"]');
  sections.forEach(sec => sec.style.display = 'none');

  const targetSection = document.getElementById(section + 'Section');
  if (targetSection) {
    targetSection.style.display = 'block';
  }

  document.querySelectorAll('.tab').forEach(item => item.classList.remove('active'));
  if (tabElement && tabElement.classList) {
    tabElement.classList.add('active');
  }

  if (section === 'analytics' && !chartsInitialized) {
    setTimeout(() => {
      initCharts();
      chartsInitialized = true;
    }, 100);
  }
}

function initCharts() {
  if (typeof chartData === 'undefined') {
    return;
  }

  const charts = [
    {
      id: 'timelineChart',
      dataProp: 'timeline',
      config: () => ({
        type: 'line',
        data: chartData.timeline,
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: { legend: { display: false } },
          scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } }
        }
      })
    },
    {
      id: 'approvalRateChart',
      dataProp: 'approval',
      config: () => ({
        type: 'bar',
        data: chartData.approval,
        options: {
          responsive: true,
          maintainAspectRatio: false,
          indexAxis: 'y',
          plugins: { legend: { display: false } },
          scales: { x: { beginAtZero: true, max: 100 } }
        }
      })
    },
    {
      id: 'monthlyChart',
      dataProp: 'monthly',
      config: () => ({
        type: 'bar',
        data: chartData.monthly,
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: { legend: { display: false } },
          scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } }
        }
      })
    },
    {
      id: 'paperTypeChart',
      dataProp: 'types',
      config: () => ({
        type: 'doughnut',
        data: chartData.types,
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: { legend: { position: 'bottom' } }
        }
      })
    }
  ];

  charts.forEach(({ id, dataProp, config }) => {
    const canvas = document.getElementById(id);
    if (canvas && typeof chartData !== 'undefined' && chartData[dataProp] !== undefined) {
      new Chart(canvas.getContext('2d'), config());
    }
  });
}

function expandChart(type) {
  const ctxCanvas = document.getElementById('modalChartCanvas');
  if (!ctxCanvas || typeof chartData === 'undefined') {
    return;
  }

  const ctx = ctxCanvas.getContext('2d');
  if (modalChartInstance) {
    modalChartInstance.destroy();
  }

  const labelElement = document.getElementById('chartModalLabel');
  const config = { type: 'bar', data: {}, options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: true } } } };

  if (type === 'timeline') {
    config.type = 'line';
    config.data = JSON.parse(JSON.stringify(chartData.timeline));
    config.options.scales = { y: { beginAtZero: true } };
    if (labelElement) {
      labelElement.innerHTML = '<i class="bi bi-calendar3 me-2"></i>Submission Timeline (Expanded)';
    }
  } else if (type === 'approval') {
    config.type = 'bar';
    config.data = JSON.parse(JSON.stringify(chartData.approval));
    config.options.indexAxis = 'y';
    config.options.scales = { x: { beginAtZero: true, max: 100 } };
    if (labelElement) {
      labelElement.innerHTML = '<i class="bi bi-percent me-2"></i>Approval Rates (Expanded)';
    }
  } else if (type === 'monthly') {
    config.type = 'bar';
    config.data = JSON.parse(JSON.stringify(chartData.monthly));
    config.options.scales = { y: { beginAtZero: true } };
    if (labelElement) {
      labelElement.innerHTML = '<i class="bi bi-calendar-check me-2"></i>Monthly Comparison (Expanded)';
    }
  } else if (type === 'types') {
    config.type = 'doughnut';
    config.data = JSON.parse(JSON.stringify(chartData.types));
    if (labelElement) {
      labelElement.innerHTML = '<i class="bi bi-pie-chart me-2"></i>Paper Type Distribution (Expanded)';
    }
  }

  modalChartInstance = new Chart(ctx, config);
  const modalElement = document.getElementById('chartModal');
  if (modalElement && window.bootstrap && window.bootstrap.Modal) {
    new bootstrap.Modal(modalElement).show();
  }
}

function filterReports() {
  const search = document.getElementById('reportSearch');
  const year = document.getElementById('reportYear');
  const program = document.getElementById('reportProgram');
  const type = document.getElementById('reportType');
  if (!search || !year || !program || !type) {
    return;
  }

  const searchValue = search.value.toLowerCase();
  const rows = document.querySelectorAll('.report-row');

  rows.forEach(row => {
    const tTitle = row.getAttribute('data-title')?.toLowerCase() ?? '';
    const tAuthor = row.getAttribute('data-author')?.toLowerCase() ?? '';
    const tProgram = row.getAttribute('data-program') ?? '';
    const tType = row.getAttribute('data-type') ?? '';
    const tYear = row.getAttribute('data-year') ?? '';

    const matchSearch = tTitle.includes(searchValue) || tAuthor.includes(searchValue);
    const matchYear = year.value === '' || tYear === year.value;
    const matchProgram = program.value === '' || tProgram === program.value;
    const matchType = type.value === '' || tType === type.value;

    row.style.display = (matchSearch && matchYear && matchProgram && matchType) ? '' : 'none';
  });
}

function sortReportsTable(colIndex) {
  const table = document.getElementById('reportsTable');
  if (!table) {
    return;
  }

  const tbody = table.tBodies[0];
  const rows = Array.from(tbody.querySelectorAll('.report-row'));
  let isAsc = table.getAttribute('data-sort-dir') === 'asc';
  const multiplier = isAsc ? 1 : -1;

  rows.sort((a, b) => {
    const aText = a.cells[colIndex]?.innerText.trim() ?? '';
    const bText = b.cells[colIndex]?.innerText.trim() ?? '';

    if (colIndex === 4) {
      return (parseInt(aText, 10) - parseInt(bText, 10)) * multiplier;
    }

    return aText.localeCompare(bText) * multiplier;
  });

  table.setAttribute('data-sort-dir', isAsc ? 'desc' : 'asc');
  tbody.append(...rows);
}

function validateChecklist(event, paperId) {
  const imradChecks = [
    document.getElementById('intro' + paperId),
    document.getElementById('method' + paperId),
    document.getElementById('result' + paperId),
    document.getElementById('discussion' + paperId),
    document.getElementById('refs' + paperId)
  ];

  const fullResearchChecks = [
    document.getElementById('ch1' + paperId),
    document.getElementById('ch2' + paperId),
    document.getElementById('ch3' + paperId),
    document.getElementById('ch4' + paperId),
    document.getElementById('ch5' + paperId),
    document.getElementById('refs2' + paperId)
  ];

  const imradComplete = imradChecks.every(check => check && check.checked);
  const fullResearchComplete = fullResearchChecks.every(check => check && check.checked);

  if (!imradComplete && !fullResearchComplete) {
    alert('Please complete either the IMRAD format checklist (Introduction, Method, Result, Discussion, References) OR the Full Research format checklist (Chapter 1-5, References) before approving.');
    return false;
  }

  const form = event.target.closest('form');
  if (!form) {
    return false;
  }

  const map = {
    'imrad_intro': 'intro',
    'imrad_method': 'method',
    'imrad_result': 'result',
    'imrad_discussion': 'discussion',
    'imrad_references': 'refs',
    'full_ch1': 'ch1',
    'full_ch2': 'ch2',
    'full_ch3': 'ch3',
    'full_ch4': 'ch4',
    'full_ch5': 'ch5',
    'full_references': 'refs2'
  };

  Object.entries(map).forEach(([dbField, htmlId]) => {
    const cb = document.getElementById(htmlId + paperId);
    if (cb && cb.checked) {
      const input = document.createElement('input');
      input.type = 'hidden';
      input.name = dbField;
      input.value = '1';
      form.appendChild(input);
    }
  });

  return true;
}

function selectAllChecklist(paperId) {
  const imradChecks = document.querySelectorAll('.imrad-check-' + paperId);
  const fullChecks = document.querySelectorAll('.full-check-' + paperId);
  const allChecked = Array.from(imradChecks).every(c => c.checked) || Array.from(fullChecks).every(c => c.checked);

  imradChecks.forEach(c => { if (c.checked !== !allChecked) c.checked = !allChecked; });
  fullChecks.forEach(c => { if (c.checked !== !allChecked) c.checked = !allChecked; });
}

function updateFeedback(checkbox) {
  const form = checkbox.closest('form');
  if (!form) {
    return;
  }

  const textarea = form.querySelector('textarea[name="feedback"]');
  if (!textarea) {
    return;
  }

  const text = checkbox.value;
  if (checkbox.checked) {
    textarea.value = textarea.value ? textarea.value + '\n' + text : text;
  } else {
    let val = textarea.value;
    const search = '\n' + text;
    if (val.includes(search)) {
      val = val.replace(search, '');
    } else if (val.includes(text + '\n')) {
      val = val.replace(text + '\n', '');
    } else if (val.includes(text)) {
      val = val.replace(text, '');
    }
    textarea.value = val.trim();
  }
}

function markAllRead(e) {
  if (e) {
    e.preventDefault();
    e.stopPropagation();
  }

  fetch(notificationHandlerPath, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'action=mark_all_read'
  })
    .then(res => res.json())
    .then(data => { if (data.success) { window.location.reload(); } });
}

function viewNotification(e, id, msg, date, isRead) {
  if (e) {
    e.preventDefault();
  }

  const body = document.getElementById('notifModalBody');
  const dateElem = document.getElementById('notifModalDate');
  if (body) {
    body.innerText = msg;
  }
  if (dateElem) {
    dateElem.innerText = date;
  }

  const modalElement = document.getElementById('notificationModal');
  if (modalElement && window.bootstrap && window.bootstrap.Modal) {
    new bootstrap.Modal(modalElement).show();
  }

  if (!isRead) {
    fetch(notificationHandlerPath, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'action=mark_read&notification_id=' + encodeURIComponent(id)
    })
      .then(res => res.json())
      .then(data => {
        if (data.success && e?.target?.closest) {
          const dropdown = e.target.closest('.dropdown-item');
          if (dropdown) {
            dropdown.style.background = '#ffffff';
          }
        }
      });
  }
}

(function () {
  document.querySelectorAll('a[href^="#"]').forEach(anchor => {
    anchor.addEventListener('click', function (e) {
      e.preventDefault();
      const target = document.querySelector(this.getAttribute('href'));
      if (target) {
        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }
    });
  });

  window.addEventListener('load', () => {
    document.body.style.transition = 'opacity 0.3s ease';
  });

  // Initialize tab sections on page load via JS so CSP-blocked inline display:none is irrelevant
  document.addEventListener('DOMContentLoaded', function () {
    const activeTab = document.querySelector('.tab.active[data-tab]');
    if (activeTab) {
      switchTab(activeTab.dataset.tab, activeTab);
    }
  });
})();
