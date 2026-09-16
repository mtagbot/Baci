/* Shared physical layout for the live sheet and every printed page.
   Transforming an in-flow sheet enlarged its unscaled pagination box. Absolute
   card slots have no inline whitespace/trailing margins or unscaled flow height. */
.sheet{position:relative;width:100%;height:100%;margin:0;padding:0;direction:rtl;text-align:right}
.sheet > .card-id,.sheet > .card-back{
  position:absolute;display:block;margin:0;
  -webkit-transform:scale(var(--card-scale,1));transform:scale(var(--card-scale,1));
  -webkit-transform-origin:top right;transform-origin:top right;
}
