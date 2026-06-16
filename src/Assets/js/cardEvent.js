import { CountUp } from 'countup.js';
import { triggerGoalCelebration } from './cardUtilities.js';

const FETCH_INTERVAL_MS = 10_000;
const CENTS_DIVISOR = 100;

const COUNTUP_OPTIONS = {
    separator: ' ',
    decimal: ',',
    suffix: ' €',
};

let counterAmount = null;
let isFetching = false;

function initCounter() {
    const initialAmount = window.currentAmount / CENTS_DIVISOR;
    counterAmount = new CountUp('card-amount', initialAmount, COUNTUP_OPTIONS);

    if (counterAmount.error) {
        console.error('CountUp init failed:', counterAmount.error);
        counterAmount = null;
        return;
    }

    counterAmount.start();
}

function updateCardWidget() {
    const currentAmountUnit = window.currentAmount / CENTS_DIVISOR;
    const goal = window.goalAmount || 1;
    const percentage = Math.min(100, Math.round((currentAmountUnit / goal) * 100));

    const barFill = document.getElementById('card-bar-fill');
    const percentEl = document.getElementById('card-percentage');
    const donorsEl = document.getElementById('card-donors');

    if (counterAmount) {
        counterAmount.update(currentAmountUnit);
    } else {
        const amountEl = document.getElementById('card-amount');
        if (amountEl) {
            amountEl.textContent = currentAmountUnit.toLocaleString('fr-FR') + ' €';
        }
    }

    if (barFill) barFill.style.width = `${percentage}%`;
    if (percentEl) percentEl.textContent = `${percentage}%`;
    if (donorsEl) donorsEl.textContent = window.donorCount ?? 0;
}

async function fetchEventData() {
    if (isFetching) {
        return;
    }

    isFetching = true;

    try {
        const response = await fetch(`/widget-event-card/${window.charityEventId}/fetch`);

        if (!response.ok) {
            console.error('Failed to fetch event data:', response.status);
            return;
        }

        const { amount, donors, goal } = await response.json();
        window.currentAmount = amount;
        window.donorCount = donors ?? window.donorCount;

        if (goal && goal !== window.goalAmount) {
            triggerGoalCelebration(goal);
            window.goalAmount = goal;
        }

        updateCardWidget();
    } catch (error) {
        console.error('Network error:', error);
    } finally {
        isFetching = false;
    }
}

function init() {
    initCounter();
    updateCardWidget();
    fetchEventData();
    setInterval(fetchEventData, FETCH_INTERVAL_MS);
}

init();

export { fetchEventData };
