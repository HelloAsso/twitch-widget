function updateDonationBar(counterback, counterfront) {
    const currentAmountUnit = window.currentAmount / 100;
    const percentage = Math.min(100, (currentAmountUnit / window.goalAmount) * 100);
    if (counterback) counterback.update(currentAmountUnit);
    if (counterfront) counterfront.update(currentAmountUnit);
    document.querySelector('div.front').style["-webkit-clip-path"] = 'inset(0 ' + (100 - percentage) + '% 0 0 round 999px)';
}


export {
    updateDonationBar as default
}
