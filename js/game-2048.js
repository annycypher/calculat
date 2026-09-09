// 2048: соединяйте плитки, соберите 2048.
const board = document.getElementById('g2048Board');
const scoreEl = document.getElementById('g2048Score');
const bestEl = document.getElementById('g2048Best');
const msgEl = document.getElementById('g2048Msg');
const newBtn = document.getElementById('g2048New');

let grid = [], score = 0, best = parseInt(localStorage.getItem('g2048-best') || '0', 10), won = false;

const SIZE = 4;

function empty() { return Array.from({ length: SIZE }, () => new Array(SIZE).fill(0)); }
function rand(a, b) { return Math.floor(Math.random() * (b - a + 1)) + a; }
function clone(g) { return g.map((r) => r.slice()); }
function cells() { const out = []; for (let r = 0; r < SIZE; r++) for (let c = 0; c < SIZE; c++) out.push([r, c]); return out; }

function spawn() {
  const empt = cells().filter(([r, c]) => grid[r][c] === 0);
  if (!empt.length) return;
  const [r, c] = empt[rand(0, empt.length - 1)];
  grid[r][c] = Math.random() < 0.9 ? 2 : 4;
}

function slideRow(row) {
  const nums = row.filter((v) => v);
  const out = []; let gained = 0;
  for (let i = 0; i < nums.length; i++) {
    if (i + 1 < nums.length && nums[i] === nums[i + 1]) { out.push(nums[i] * 2); gained += nums[i] * 2; i++; }
    else out.push(nums[i]);
  }
  while (out.length < SIZE) out.push(0);
  return { row: out, gained };
}
function moveLeft(g) {
  let moved = false, gained = 0;
  const ng = g.map((row) => {
    const { row: r, gained: gd } = slideRow(row);
    if (r.join(',') !== row.join(',')) moved = true;
    gained += gd;
    return r;
  });
  return { grid: ng, moved, gained };
}
function transpose(g) { const i = [0, 1, 2, 3]; return i.map((x) => i.map((y) => g[y][x])); }
function reverseRows(g) { return g.map((row) => [...row].reverse()); }

function apply(dir) {
  let res;
  if (dir === 'left') res = moveLeft(grid);
  else if (dir === 'right') res = moveLeft(reverseRows(grid)), res.grid = reverseRows(res.grid);
  else if (dir === 'up') res = moveLeft(transpose(grid)), res.grid = transpose(res.grid);
  else res = moveLeft(transpose(reverseRows(grid))), res.grid = reverseRows(transpose(res.grid));
  if (!res.moved) return false;
  grid = res.grid; score += res.gained;
  if (score > best) { best = score; try { localStorage.setItem('g2048-best', String(best)); } catch (e) {} }
  spawn();
  render();
  return true;
}

function hasWon() { return grid.flat().some((v) => v >= 2048); }
function noMoves() {
  if (grid.flat().some((v) => v === 0)) return false;
  for (let r = 0; r < SIZE; r++) for (let c = 0; c < SIZE; c++) {
    if (c + 1 < SIZE && grid[r][c] === grid[r][c + 1]) return false;
    if (r + 1 < SIZE && grid[r][c] === grid[r + 1][c]) return false;
  }
  return true;
}

function render() {
  board.innerHTML = grid.flat().map((v) => `<div class="tile-2048" data-v="${v}">${v === 0 ? '' : v}</div>`).join('');
  scoreEl.textContent = score;
  bestEl.textContent = best;
  msgEl.textContent = '';
}

function newGame() {
  grid = empty(); score = 0; won = false;
  spawn(); spawn();
  render();
}

function check() {
  if (hasWon() && !won) { won = true; msgEl.textContent = 'Вы собрали 2048! Можно продолжать.'; }
  if (noMoves()) { msgEl.textContent = 'Игра окончена — ходов нет. Нажмите «Новая игра».'; }
}

const KEYMAP = { ArrowUp: 'up', ArrowDown: 'down', ArrowLeft: 'left', ArrowRight: 'right' };
window.addEventListener('keydown', (e) => {
  const dir = KEYMAP[e.key];
  if (!dir) return;
  e.preventDefault();
  if (apply(dir)) check();
});

let sx = 0, sy = 0;
window.addEventListener('touchstart', (e) => { sx = e.touches[0].clientX; sy = e.touches[0].clientY; }, { passive: true });
window.addEventListener('touchend', (e) => {
  const dx = e.changedTouches[0].clientX - sx, dy = e.changedTouches[0].clientY - sy;
  const ax = Math.abs(dx), ay = Math.abs(dy);
  if (Math.max(ax, ay) < 24) return;
  const dir = ax > ay ? (dx > 0 ? 'right' : 'left') : (dy > 0 ? 'down' : 'up');
  if (apply(dir)) check();
}, { passive: true });

if (newBtn) newBtn.addEventListener('click', newGame);
newGame();
