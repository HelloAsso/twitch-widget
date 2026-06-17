let isAnimating = false;

function updateDonationBar(counterback, counterfront) {
    const currentAmountUnit = window.currentAmount / 100;
    const percentage = Math.min(100, (currentAmountUnit / window.goalAmount) * 100);
    if (counterback) counterback.update(currentAmountUnit);
    if (counterfront) counterfront.update(currentAmountUnit);
    document.querySelector('div.front').style['-webkit-clip-path'] = 'inset(0 ' + (100 - percentage) + '% 0 0 round 999px)';
    document.querySelector('div.front').style['clip-path'] = 'inset(0 ' + (100 - percentage) + '% 0 0 round 999px)';
}

function triggerGoalAnimation(newGoal, counterback, counterfront, isLastGoal = false) {
    if (isAnimating) return;
    isAnimating = true;

    const front = document.querySelector('div.front');

    // Phase 1 : remplir à 100 %
    front.style['-webkit-clip-path'] = 'inset(0 0% 0 0 round 999px)';
    front.style['clip-path'] = 'inset(0 0% 0 0 round 999px)';

    // Phase 2 : shimmer
    setTimeout(() => {
        front.classList.add('front--celebrating');

        // Phase 3 : mise à jour ou maintien à 100 %
        setTimeout(() => {
            front.classList.remove('front--celebrating');

            if (isLastGoal) {
                updateDonationBar(counterback, counterfront);
                isAnimating = false;
            } else {
                window.goalAmount = newGoal;
                ['back-goal-total', 'front-goal-total'].forEach((id) => {
                    const el = document.getElementById(id);
                    if (el) el.textContent = `${newGoal} €`;
                });
                updateDonationBar(counterback, counterfront);
                isAnimating = false;
            }
        }, 2500);
    }, 1200);
}

export { updateDonationBar as default, triggerGoalAnimation };
