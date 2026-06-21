import confetti from "canvas-confetti";

/** Comemora a conclusão de uma tarefa com uma rajada de confete. */
export function fireConfetti() {
  confetti({
    particleCount: 90,
    spread: 70,
    startVelocity: 38,
    origin: { y: 0.7 },
    scalar: 0.9,
  });

  setTimeout(() => {
    confetti({ particleCount: 50, angle: 60, spread: 55, origin: { x: 0 } });
  }, 120);

  setTimeout(() => {
    confetti({ particleCount: 50, angle: 120, spread: 55, origin: { x: 1 } });
  }, 240);
}
