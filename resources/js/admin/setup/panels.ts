import type { ComponentType } from 'react';
import type { StepKey } from './model';
import { HoursStep } from './steps/HoursStep';
import { LinkStep } from './steps/LinkStep';
import { PaymentsStep } from './steps/PaymentsStep';
import { ProfileStep } from './steps/ProfileStep';
import { ServicesStep } from './steps/ServicesStep';
import { TeamStep } from './steps/TeamStep';

/*
 * Each step's panel by its key, one file per step under steps/. A panel takes no props: it reads
 * its data and words and talks to the wizard through the hooks in step.tsx.
 */
export const PANELS: Record<StepKey, ComponentType> = {
    profile: ProfileStep,
    services: ServicesStep,
    team: TeamStep,
    hours: HoursStep,
    payments: PaymentsStep,
    link: LinkStep,
};
