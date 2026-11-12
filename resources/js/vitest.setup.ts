/*
 * React refuses to flush act() outside an environment that declares itself one.
 */
(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

/*
 * What jsdom lacks and the admin's controls call, spelled once for every suite. jsdom lays nothing
 * out, so scrolling an element into view has nothing to do: the time field keeps its highlighted
 * option in view that way.
 */
Element.prototype.scrollIntoView = function scrollIntoView(): void {};
