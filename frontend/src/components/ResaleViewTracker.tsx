"use client";

import { useEffect, useRef } from "react";
import { recordResaleView } from "@/lib/actions/events";

export default function ResaleViewTracker({ matchId }: { matchId: number }) {
  const sent = useRef(false);
  useEffect(() => {
    if (sent.current) return;
    sent.current = true;
    recordResaleView(matchId).catch(() => {});
  }, [matchId]);
  return null;
}
