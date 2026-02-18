<?php
// ============================================================
// 情報ボタンを生成する関数（タイトル＋本文をデータ属性で渡す）
// ============================================================
function getInformationButtonHtml($tooltipTitle = '情報', $tooltipText = '準備中') {
    $titleSafe = htmlspecialchars($tooltipTitle, ENT_QUOTES, 'UTF-8');
    $textSafe  = htmlspecialchars($tooltipText, ENT_QUOTES, 'UTF-8');

    return "
    <button class='info-button'
        data-tooltip-title='{$titleSafe}'
        data-tooltip-text='{$textSafe}'>
        <img src='public/img/information.png' alt='情報' class='info-icon'>
    </button>
    ";
}

// ============================================================
// グローバルツールチップを出力する関数（1ページに1つだけ）
// ============================================================
function getInformationTooltip() {
    return "
    <div id='global-tooltip' class='tooltip hidden'>
        <div class='tooltip-title'></div>
        <div class='tooltip-text'></div>
    </div>
    ";
}

// ============================================================
// JavaScript（共通ツールチップ制御）
// ============================================================
function getTooltipScript() {
    return '
    <script>
    // グローバルツールチップを表示
    function showGlobalTooltip(title, text) {
        const tooltip = document.getElementById("global-tooltip");
        if (!tooltip) return;

        tooltip.querySelector(".tooltip-title").textContent = title;
        tooltip.querySelector(".tooltip-text").textContent = text;

        tooltip.classList.remove("hidden");
        tooltip.classList.add("visible");

        clearTimeout(tooltip._hideTimer);
        tooltip._hideTimer = setTimeout(() => {
            tooltip.classList.remove("visible");
            tooltip.classList.add("hidden");
        }, 2500);
    }

    // イベント設定
    document.addEventListener("DOMContentLoaded", () => {
        document.querySelectorAll(".info-button").forEach(button => {
            button.addEventListener("click", () => {
                const title = button.dataset.tooltipTitle || "情報";
                const text  = button.dataset.tooltipText || "準備中";
                showGlobalTooltip(title, text);
            });
        });
    });
    </script>
    ';
}
?>
