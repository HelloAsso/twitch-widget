const CENTS_DIVISOR = 100;

function updateCardWidget() {
    const currentAmountUnit = window.currentAmount / CENTS_DIVISOR;
    const goal = window.goalAmount || 1;
    const percentage = Math.min(100, Math.round((currentAmountUnit / goal) * 100));

    const amountEl = document.getElementById('card-amount');
    const barFill = document.getElementById('card-bar-fill');
    const percentEl = document.getElementById('card-percentage');
    const donorsEl = document.getElementById('card-donors');

    if (amountEl) amountEl.textContent = currentAmountUnit.toLocaleString('fr-FR') + ' €';
    if (barFill) barFill.style.width = `${percentage}%`;
    if (percentEl) percentEl.textContent = `${percentage}%`;
    if (donorsEl) donorsEl.textContent = window.donorCount ?? 0;
}

export default updateCardWidget;
