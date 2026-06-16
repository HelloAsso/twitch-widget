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

const COLORS = ['#ff4d4d', '#ffa500', '#ffe600', '#4dff91', '#4dc8ff', '#c44dff', '#ff4dbb', '#fff'];

function triggerGoalCelebration(newGoal) {
    const overlay = document.getElementById('fireworks-overlay');
    if (!overlay) return;

    const W = window.innerWidth;
    const H = window.innerHeight;

    // 5 bursts at random positions
    for (let b = 0; b < 5; b++) {
        const cx = Math.random() * W;
        const cy = Math.random() * H * 0.7 + H * 0.05;
        const delay = b * 280;

        for (let i = 0; i < 18; i++) {
            const angle = (i / 18) * Math.PI * 2;
            const dist = 60 + Math.random() * 80;
            const tx = Math.cos(angle) * dist;
            const ty = Math.sin(angle) * dist - 40;
            const color = COLORS[Math.floor(Math.random() * COLORS.length)];
            const dur = (0.8 + Math.random() * 0.6).toFixed(2) + 's';

            const p = document.createElement('div');
            p.className = 'fw-particle';
            p.style.cssText = `left:${cx}px;top:${cy}px;background:${color};--tx:${tx}px;--ty:${ty}px;--dur:${dur};animation-delay:${delay}ms;`;
            overlay.appendChild(p);

            setTimeout(() => p.remove(), delay + 1600);
        }
    }

    // Banner
    const banner = document.createElement('div');
    banner.className = 'goal-banner';
    banner.textContent = `🎯 Objectif atteint ! Prochain objectif : ${newGoal} €`;
    overlay.appendChild(banner);
    setTimeout(() => banner.remove(), 3500);

    // Flash the goal text
    const goalEl = document.getElementById('card-goal-text');
    if (goalEl) {
        goalEl.innerHTML = `Objectif : <strong>${newGoal} €</strong>`;
        goalEl.classList.remove('card-widget__goal--flash');
        void goalEl.offsetWidth;
        goalEl.classList.add('card-widget__goal--flash');
        goalEl.addEventListener('animationend', () => goalEl.classList.remove('card-widget__goal--flash'), { once: true });
    }
}

export default updateCardWidget;
export { triggerGoalCelebration };
