// Быстрый счёт: 60 секунд, +1 за верный ответ, три уровня.
const intro = document.getElementById('gameIntro');
const play = document.getElementById('gamePlay');
const over = document.getElementById('gameOver');
const scoreEl = document.getElementById('qScore');
const timeEl = document.getElementById('qTime');
const qEl = document.getElementById('qQuestion');
const ansEl = document.getElementById('qAnswers');
const msgEl = document.getElementById('qMsg');
const startBtn = document.getElementById('quickStart');
const againBtn = document.getElementById('qAgain');

let level = 'medium';
let score = 0, timeLeft = 60, timer = null, correct = 0, answered = false;

function rand(a, b) { return Math.floor(Math.random() * (b - a + 1)) + a; }
function shuffle(a) { for (let i = a.length - 1; i > 0; i--) { const j = rand(0, i); [a[i], a[j]] = [a[j], a[i]]; } return a; }
function levelLabel() { return { easy: 'Лёгкий', medium: 'Средний', hard: 'Сложный' }[level]; }

function question() {
  let a, b, c, text, ans, r;
  if (level === 'easy') {
    a = rand(2, 20); b = rand(2, 20);
    if (Math.random() < 0.5) { text = a + ' + ' + b; ans = a + b; }
    else { a = Math.max(a, b); text = a + ' − ' + b; ans = a - b; }
  } else if (level === 'medium') {
    if (Math.random() < 0.6) { a = rand(3, 12); b = rand(3, 12); text = a + ' × ' + b; ans = a * b; }
    else { a = rand(10, 80); b = rand(2, 20); text = a + ' + ' + b; ans = a + b; }
  } else {
    r = Math.random();
    if (r < 0.4) { a = rand(4, 20); b = rand(4, 20); text = a + ' × ' + b; ans = a * b; }
    else if (r < 0.75) { b = rand(3, 12); ans = rand(3, 12); a = b * ans; text = a + ' ÷ ' + b; }
    else { a = rand(4, 15); b = rand(4, 15); c = rand(2, 9); ans = a * b + (Math.random() < 0.5 ? c : -c); text = a + ' × ' + b + ' ' + (ans > a * b ? '+' : '−') + ' ' + c; }
  }
  return { text, ans };
}

function makeAnswers(ans) {
  const set = new Set([ans]);
  let guard = 0;
  while (set.size < 4 && guard++ < 200) {
    const cand = rand(1, 25);
    let d = ans + (Math.random() < 0.5 ? -cand : cand);
    if (d < 0) d = ans + cand;
    if (d !== ans) set.add(d);
  }
  while (set.size < 4) set.add(ans + set.size * 3);
  return shuffle([...set]);
}

function stopTimer() { if (timer) { clearInterval(timer); timer = null; } }

function next() {
  const q = question();
  correct = q.ans; answered = false; msgEl.textContent = '';
  qEl.textContent = q.text;
  ansEl.innerHTML = makeAnswers(q.ans).map((a) => '<button data-a="' + a + '">' + a + '</button>').join('');
  Array.from(ansEl.children).forEach((b) => { b.disabled = false; b.classList.remove('correct', 'wrong'); });
}

function start() {
  stopTimer();
  score = 0; timeLeft = 60;
  scoreEl.textContent = '0'; timeEl.textContent = '60';
  intro.style.display = 'none'; over.style.display = 'none'; play.style.display = '';
  next();
  timer = setInterval(() => {
    timeLeft--; timeEl.textContent = timeLeft;
    if (timeLeft <= 0) end();
  }, 1000);
}

function end() {
  stopTimer();
  play.style.display = 'none'; over.style.display = '';
  const bestKey = 'quickmath-best-' + level;
  let best = parseInt(localStorage.getItem(bestKey) || '0', 10);
  if (score > best) { best = score; try { localStorage.setItem(bestKey, String(best)); } catch (e) {} }
  document.getElementById('qFinal').textContent = score;
  document.getElementById('qBest').textContent = 'Лучший результат (' + levelLabel() + '): ' + best;
}

ansEl.addEventListener('click', (e) => {
  const btn = e.target.closest('button');
  if (!btn || answered) return;
  answered = true;
  const val = parseInt(btn.dataset.a, 10);
  Array.from(ansEl.children).forEach((b) => { b.disabled = true; if (parseInt(b.dataset.a, 10) === correct) b.classList.add('correct'); });
  if (val === correct) { score++; scoreEl.textContent = score; msgEl.textContent = 'Верно!'; }
  else { btn.classList.add('wrong'); msgEl.textContent = 'Ошибка'; }
  setTimeout(next, 650);
});

document.querySelectorAll('[data-level]').forEach((btn) => {
  btn.addEventListener('click', () => {
    level = btn.dataset.level;
    document.querySelectorAll('[data-level]').forEach((b) => b.classList.toggle('btn-primary', b === btn));
  });
});

if (startBtn) startBtn.addEventListener('click', start);
if (againBtn) againBtn.addEventListener('click', start);
