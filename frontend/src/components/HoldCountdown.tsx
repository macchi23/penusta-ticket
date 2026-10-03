"use client";

import { useRouter } from "next/navigation";
import { useEffect, useRef, useState } from "react";

export default function HoldCountdown({
  expiresAt,
  serverTime,
}: {
  expiresAt: string;
  serverTime: string;
}) {
  const router = useRouter();
  const [remainingMs, setRemainingMs] = useState<number | null>(null);
  const fired = useRef(false);

  useEffect(() => {
    fired.current = false;
    // クライアント時計のずれを server_time で補正する
    const offset = new Date(serverTime).getTime() - Date.now();
    const expires = new Date(expiresAt).getTime();
    const tick = () => {
      const left = expires - (Date.now() + offset);
      setRemainingMs(Math.max(0, left));
      if (left <= 0 && !fired.current) {
        fired.current = true;
        router.refresh();
      }
    };
    tick();
    const t = setInterval(tick, 1000);
    return () => clearInterval(t);
  }, [expiresAt, serverTime, router]);

  if (remainingMs === null) return <span />;
  const total = Math.ceil(remainingMs / 1000);
  const m = Math.floor(total / 60);
  const s = String(total % 60).padStart(2, "0");
  return (
    <span className={total <= 60 ? "font-bold text-red-600" : ""}>
      残り {m}:{s}
    </span>
  );
}
