<?php
/**
 * Progress Tracker Component
 * Shows the 5-stage approval workflow with visual indicators
 * 
 * Stages:
 * 1. Faculty (Research Adviser)
 * 2. Admin L1 (Research Coordinator)
 * 3. Admin L2 (HAP)
 * 4. Head of Academic Affairs
 * 5. Director (Super Admin)
 */

function render_progress_tracker($current_status) {
    $icon_green = '<i class="ph-fill ph-check-circle" style="font-size: 24px; color: #10b981;"></i>';
    $icon_yellow = '<i class="ph-fill ph-check-circle" style="font-size: 24px; color: #dca92c;"></i>';
    
    // Define stages
    $stages = [
        1 => ['label' => 'Faculty', 'statuses' => ['pending_faculty']],
        2 => ['label' => 'Research Coordinator', 'statuses' => ['pending_admin_l1', 'pending_admin']],
        3 => ['label' => 'HAP', 'statuses' => ['pending_head_academic', 'pending_admin_l2']],
        4 => ['label' => 'Director', 'statuses' => ['pending_super_admin', 'approved']]
    ];
    
    // Determine current stage and completed stages
    $current_stage = 0;
    $completed_stages = [];
    
    if ($current_status === 'draft') {
        $current_stage = 0;
    } elseif (in_array($current_status, $stages[1]['statuses'])) {
        $current_stage = 1;
    } elseif (in_array($current_status, $stages[2]['statuses'])) {
        $current_stage = 2;
        $completed_stages = [1];
    } elseif (in_array($current_status, $stages[3]['statuses'])) {
        $current_stage = 3;
        $completed_stages = [1, 2];
    } elseif (in_array($current_status, $stages[4]['statuses'])) {
        if ($current_status === 'approved') {
            $completed_stages = [1, 2, 3, 4];
        } else {
            $current_stage = 4;
            $completed_stages = [1, 2, 3];
        }
    }
    
    // Calculate progress line width
    $progress_width = 0;
    $total_stages = 4;
    $gaps = $total_stages - 1;
    
    if (count($completed_stages)> 0) {
        $progress_width = (max($completed_stages) / $gaps) * 100;
    }
    if ($current_stage> 0 && !in_array($current_stage, $completed_stages)) {
        $progress_width = (($current_stage - 1) / $gaps) * 100;
    }
    
    ?>
    <div class="progress-tracker">
        <div class="progress-line">
            <div class="progress-line-fill" style="width: <?= $progress_width ?>%;"></div>
        </div>
        
        <?php for ($i = 1; $i <= 4; $i++): ?>
            <div class="progress-step">
                <?php if (in_array($i, $completed_stages)): ?>
                    <div class="step-circle completed"><?= $icon_green ?></div>
                    <div class="step-label completed"><?= $stages[$i]['label'] ?></div>
                <?php elseif ($i === $current_stage): ?>
                    <div class="step-circle current"><?= $icon_yellow ?></div>
                    <div class="step-label current"><?= $stages[$i]['label'] ?></div>
                <?php else: ?>
                    <div class="step-circle default"><?= $i ?></div>
                    <div class="step-label default"><?= $stages[$i]['label'] ?></div>
                <?php endif; ?>
            </div>
        <?php endfor; ?>
    </div>
    
    <style nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
        .progress-tracker { 
            display: flex; 
            align-items: flex-start; 
            justify-content: space-between; 
            position: relative; 
            min-width: 400px; 
            max-width: 100%; 
            margin: 0 auto; 
        }
        .progress-step { 
            display: flex; 
            flex-direction: column; 
            align-items: center; 
            z-index: 2; 
            flex: 1;
        }
        .step-circle { 
            width: 40px; 
            height: 40px; 
            border-radius: 50%; 
            border: 2px solid #ccc; 
            background: white; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            font-size: 14px; 
            font-weight: bold; 
            color: #ccc;
            transition: all 0.3s ease;
        }
        .step-circle:hover {
            transform: scale(1.1);
        }
        .step-circle.completed { 
            border-color: #10b981; 
            background-color: white; 
            color: #10b981; 
        }
        .step-circle.current { 
            border-color: #fbbf24; 
            background-color: white; 
            color: #fbbf24; 
            animation: pulse 2s infinite; 
        }
        @keyframes pulse {
            0%, 100% { box-shadow: 0 0 0 0 rgba(251, 191, 36, 0.7); }
            50% { box-shadow: 0 0 0 8px rgba(251, 191, 36, 0); }
        }
        .step-label { 
            font-size: 11px; 
            font-weight: 600; 
            margin-top: 6px; 
            color: #64748b; 
            transition: color 0.3s ease;
            text-align: center;
            max-width: 100px;
            line-height: 1.2;
        }
        .step-label.completed, .step-label.current { color: #1e293b; font-weight: 700; }
        .progress-line { 
            position: absolute; 
            top: 19px; 
            left: 40px; 
            right: 40px; 
            height: 3px; 
            background: #e0e0e0; 
            z-index: 1; 
            border-radius: 2px; 
        }
        .progress-line-fill { 
            height: 100%; 
            background: linear-gradient(90deg, #10b981, #059669); 
            transition: width 0.6s ease;
            border-radius: 2px;
        }
    </style>
    <?php
}
?>
